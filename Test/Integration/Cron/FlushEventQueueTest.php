<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Engine\Client as EngineClient;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\Handler\ContactSyncHandler;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Queue\Pending;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\RecordingHandler;

/**
 * The queue flush cron end-to-end against a real database: delivery
 * outcomes, batch failures, missing handlers and stale-claim recovery with
 * scriptable handler stubs — plus the retry classification (PRO-1800/1763)
 * driven through the REAL contact-sync handler and Smaily client, with only
 * the HTTP transport faked.
 */
class FlushEventQueueTest extends IntegrationTestCase
{
    private EventQueue $queue;

    /** @var array<int, array{request: Request}> what the fake transport received */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
    }

    public function testSuccessfulRunDeliversAllClaimedEvents(): void
    {
        $this->queue->enqueue('contact.sync', ['email' => 'a@example.com'], null, 0, 'f-1');
        $this->queue->enqueue('contact.sync', ['email' => 'b@example.com'], null, 0, 'f-2');

        $handler = new RecordingHandler(
            static fn (array $events): array => array_fill_keys(
                array_map(static fn (Event $event): int => (int)$event->getId(), $events),
                true
            )
        );
        $this->runCron(['contact.sync' => $handler]);

        self::assertCount(1, $handler->getBatches(), 'One batch per event type per run');
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_SENT, $row['status']);
        }
    }

    public function testPerEventErrorsMarkOnlyThoseEventsFailed(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-ok');
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-bad');

        $handler = new RecordingHandler(static function (array $events): array {
            $results = [];
            foreach ($events as $event) {
                $results[(int)$event->getId()] = $event->getEventUuid() === 'f-bad'
                    ? 'recipient rejected'
                    : true;
            }

            return $results;
        });
        $this->runCron(['contact.sync' => $handler]);

        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_SENT, $rows['f-ok']['status']);
        self::assertSame(Event::STATUS_PENDING, $rows['f-bad']['status'], 'Failed event is rescheduled');
        self::assertSame('1', (string)$rows['f-bad']['attempts']);
        self::assertSame('recipient rejected', $rows['f-bad']['last_error']);
    }

    public function testClientExceptionReschedulesTheWholeBatch(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-b1');
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-b2');

        $handler = new RecordingHandler(static function (): array {
            throw new SmailyClientException('Smaily API is unreachable');
        });
        $this->runCron(['contact.sync' => $handler]);

        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_PENDING, $row['status']);
            self::assertSame('1', (string)$row['attempts']);
            self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
            self::assertSame('Smaily API is unreachable', $row['last_error']);
        }
    }

    public function testEventsWithoutAHandlerAreParkedImmediately(): void
    {
        $this->queue->enqueue('unknown.event', [], null, 0, 'f-unknown');

        $this->runCron([]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status'], 'Retrying cannot make a handler appear');
        self::assertSame('No handler registered for "unknown.event"', $row['last_error']);
        self::assertSame('1', (string)$row['attempts'], 'PRO-1961: the one attempt made, not five');
        self::assertNull($row['next_retry_at']);
    }

    /**
     * PRO-1961: a payload the handler cannot send never improves on retry,
     * so it fails on the first attempt and nothing is posted.
     */
    public function testAMalformedContactPayloadFailsOnTheFirstAttempt(): void
    {
        $this->queue->enqueue('contact.sync', ['store_id' => 0], null, 0, 'f-malformed');

        $this->runCron(['contact.sync' => $this->realContactSync([])]);

        self::assertSame([], $this->requests, 'Nothing was posted');
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status']);
        self::assertSame('1', (string)$row['attempts']);
        self::assertNull($row['next_retry_at']);
        self::assertSame('Malformed contact payload', $row['last_error']);
    }

    /**
     * PRO-1961: an engine 4xx on an identity merge is a refusal of that
     * payload — failed on the first attempt with the same named class as a
     * Smaily refusal (PRO-1800), the engine's answer kept for Details.
     */
    public function testAnEngineRefusalOfAnIdentityMergeFailsOnTheFirstAttempt(): void
    {
        $this->queue->enqueue(
            EventType::ENGINE_IDENTITY_MERGE,
            ['customer_email' => 'a@example.com', 'customer_external_id' => '42', 'store_id' => 1],
            '42',
            1,
            'f-merge-refused'
        );

        $this->runCron([EventType::ENGINE_IDENTITY_MERGE => $this->identityMerge(
            new EngineRequestException('Engine request failed with HTTP 422: unknown session', 422)
        )]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status']);
        self::assertSame('1', (string)$row['attempts'], 'The other four attempts are not spent');
        self::assertNull($row['next_retry_at']);
        self::assertSame(
            'permanent_http_422: Engine request failed with HTTP 422: unknown session',
            $row['last_error']
        );
        self::assertSame(
            ['http_status' => 422, 'body' => ['error' => 'unknown_session']],
            json_decode((string)$row['last_response'], true)
        );
    }

    /**
     * PRO-2466: while Campaign Intelligence refuses the account, identity
     * merge rows wait like the engine ingest rows: not claimed, no attempt
     * spent, nothing written on them — and the contact sync beside them
     * still goes out.
     */
    public function testIdentityMergeRowsWaitWhileTheAccountIsRefused(): void
    {
        $this->queue->enqueue(
            EventType::ENGINE_IDENTITY_MERGE,
            ['customer_email' => 'a@example.com', 'customer_external_id' => '42', 'store_id' => 1],
            '42',
            1,
            'f-merge-waits'
        );
        $this->enqueueContact('f-contact-goes');

        $this->runCron([
            EventType::ENGINE_IDENTITY_MERGE => $this->identityMerge(new \LogicException('not called'), true),
            'contact.sync' => $this->realContactSync([new Response(200, [], '{"code":101,"message":"OK"}')]),
        ]);

        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_SENT, $rows['f-contact-goes']['status']);
        $merge = $rows['f-merge-waits'];
        self::assertSame(Event::STATUS_PENDING, $merge['status']);
        self::assertSame('0', (string)$merge['attempts']);
        self::assertNull($merge['claim_token'], 'Never claimed');
        self::assertNull($merge['last_error']);
        self::assertNull($merge['next_retry_at']);
    }

    /**
     * PRO-2466: a row a handler leaves pending mid-run (the merge that met
     * the refusal) goes back as it was.
     */
    public function testARowLeftPendingGoesBackAsItWas(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-left-pending');

        $this->runCron(['contact.sync' => new RecordingHandler(
            static fn (array $events): array => array_fill_keys(
                array_map(static fn (Event $event): int => (int)$event->getId(), $events),
                new Pending()
            )
        )]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertSame('0', (string)$row['attempts']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['last_error']);
        self::assertNull($row['next_retry_at']);
    }

    private function identityMerge(\Throwable $failure, bool $refused = false): IdentityMergeHandler
    {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturn($refused);
        $client = $this->createMock(EngineClient::class);
        $client->method('identityMerge')->willThrowException($failure);
        $client->method('lastExchange')->willReturn([
            'request' => ['customer_email' => 'a@example.com'],
            'response' => ['http_status' => 422, 'body' => ['error' => 'unknown_session']],
        ]);
        $consent = $this->createMock(ProfilingConsent::class);
        $consent->method('isAllowed')->willReturn(true);

        return $this->objectManager->create(IdentityMergeHandler::class, [
            'settings' => $settings,
            'client' => $client,
            'profilingConsent' => $consent,
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
        ]);
    }

    public function testStaleClaimIsRecoveredAndDeliveredInTheSameRun(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'f-stale');
        $this->queue->claimBatch();
        $this->clock->travel(901);

        $handler = new RecordingHandler(
            static fn (array $events): array => array_fill_keys(
                array_map(static fn (Event $event): int => (int)$event->getId(), $events),
                true
            )
        );
        $this->runCron(['contact.sync' => $handler]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status'], 'Stale sending rows are requeued, then delivered');
    }

    public function testAPermanentRefusalIsRecordedFailedOnTheFirstAttempt(): void
    {
        $this->enqueueContact('f-refused');

        $this->runCron(['contact.sync' => $this->realContactSync([new Response(404, [], 'Not found')])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status'], 'A refusal retrying cannot change stops at once');
        self::assertSame('1', (string)$row['attempts'], 'The other four attempts are not spent');
        self::assertNull($row['next_retry_at']);
        self::assertStringContainsString('permanent_http_404', (string)$row['last_error']);
    }

    /**
     * PRO-1962: Smaily answers HTTP 200 with the envelope code 203 "invalid
     * data" — resending identical data can never succeed, so the row fails
     * on the first attempt with a named class, Smaily's answer kept.
     */
    public function testAnInvalidDataEnvelopeIsRecordedFailedOnTheFirstAttempt(): void
    {
        $this->enqueueContact('f-invalid-data');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":203,"message":"Invalid data"}'),
        ])]);

        self::assertCount(1, $this->requests, 'A single contact is not sent again');
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status']);
        self::assertSame('1', (string)$row['attempts'], 'The other four attempts are not spent');
        self::assertNull($row['next_retry_at']);
        self::assertSame('permanent_envelope_203: Smaily API returned code 203: Invalid data', $row['last_error']);
        self::assertSame(
            ['http_status' => 200, 'body' => ['code' => 203, 'message' => 'Invalid data']],
            json_decode((string)$row['last_response'], true)
        );
    }

    /**
     * PRO-3753: Smaily answers one code per request, so a 203 on a group of
     * contacts goes again one contact per request in the same run: the
     * valid contacts sync, only the refused one fails with Smaily's answer.
     */
    public function testAnInvalidDataEnvelopeOnAGroupSendsEachContactAlone(): void
    {
        $this->enqueueContacts(5);

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":203,"message":"Invalid data"}'),
            new Response(200, [], '{"code":101,"message":"OK"}'),
            new Response(200, [], '{"code":101,"message":"OK"}'),
            new Response(200, [], '{"code":203,"message":"Invalid email"}'),
            new Response(200, [], '{"code":101,"message":"OK"}'),
            new Response(200, [], '{"code":101,"message":"OK"}'),
        ])]);

        self::assertCount(6, $this->requests, 'The group, then each of its five contacts alone');
        self::assertSame($this->contacts(1, 2, 3, 4, 5), $this->postedBody(0));
        foreach ([1, 2, 3, 4, 5] as $n) {
            self::assertSame($this->contacts($n), $this->postedBody($n));
        }

        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        foreach (['f-c1', 'f-c2', 'f-c4', 'f-c5'] as $uuid) {
            self::assertSame(Event::STATUS_SENT, $rows[$uuid]['status'], $uuid);
            self::assertNull($rows[$uuid]['last_error']);
            self::assertSame(
                ['http_status' => 200, 'body' => ['code' => 101, 'message' => 'OK']],
                json_decode((string)$rows[$uuid]['last_response'], true)
            );
        }
        $refused = $rows['f-c3'];
        self::assertSame(Event::STATUS_FAILED, $refused['status']);
        self::assertSame('1', (string)$refused['attempts']);
        self::assertNull($refused['next_retry_at']);
        self::assertSame('permanent_envelope_203: Smaily API returned code 203: Invalid email', $refused['last_error']);
        self::assertSame($this->contacts(3), json_decode((string)$refused['sent_payload'], true));
        self::assertSame(
            ['http_status' => 200, 'body' => ['code' => 203, 'message' => 'Invalid email']],
            json_decode((string)$refused['last_response'], true)
        );
    }

    /**
     * PRO-1964: Smaily's one answer to a group is recorded in one UPDATE,
     * not one per row — and each row still ends on its own step of the
     * ladder, with its own part of the request body.
     */
    public function testAGroupFailureIsRecordedInOneWriteWithEachRowOnItsOwnStep(): void
    {
        $this->enqueueContacts(5);
        // f-c5 failed three times before: this failure is its fourth.
        $this->connection->update(EventResource::TABLE_NAME, ['attempts' => 3], ['event_uuid = ?' => 'f-c5']);

        $writes = $this->failureWrites(fn () => $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(503, [], 'Service unavailable'),
        ])]));

        self::assertSame(1, $writes, 'One statement for the five rows');
        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        foreach ([1, 2, 3, 4, 5] as $n) {
            $row = $rows['f-c' . $n];
            self::assertSame(Event::STATUS_PENDING, $row['status']);
            self::assertSame($n === 5 ? '4' : '1', (string)$row['attempts']);
            self::assertSame(
                $this->clockDate(EventQueue::BACKOFF_SECONDS[$n === 5 ? 3 : 0]),
                $row['next_retry_at'],
                'Each row on its own step of the ladder'
            );
            self::assertSame('Smaily API request failed with HTTP 503: Service unavailable', $row['last_error']);
            self::assertSame($this->contacts($n), json_decode((string)$row['sent_payload'], true));
            self::assertSame(
                ['http_status' => 503, 'body' => 'Service unavailable'],
                json_decode((string)$row['last_response'], true)
            );
            self::assertNull($row['claim_token'], 'The claim is cleared as a single failure clears it');
        }
    }

    /**
     * PRO-1964: a group that reaches the last step parks the rows whose
     * attempts are spent and reschedules the others, in the same write.
     */
    public function testAGroupFailureParksOnlyTheRowsWhoseAttemptsAreSpent(): void
    {
        $this->enqueueContacts(3);
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['attempts' => EventQueue::MAX_ATTEMPTS - 1],
            ['event_uuid = ?' => 'f-c2']
        );

        $writes = $this->failureWrites(fn () => $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(503, [], 'Service unavailable'),
        ])]));

        self::assertSame(1, $writes);
        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_FAILED, $rows['f-c2']['status']);
        self::assertSame((string)EventQueue::MAX_ATTEMPTS, (string)$rows['f-c2']['attempts']);
        self::assertNull($rows['f-c2']['next_retry_at']);
        foreach (['f-c1', 'f-c3'] as $uuid) {
            self::assertSame(Event::STATUS_PENDING, $rows[$uuid]['status']);
            self::assertSame('1', (string)$rows[$uuid]['attempts']);
            self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $rows[$uuid]['next_retry_at']);
        }
    }

    /**
     * PRO-1964: rows that fail for reasons of their own keep them — one
     * write per reason, the rows of a shared reason together.
     */
    public function testRowsThatFailForDifferentReasonsKeepTheirOwn(): void
    {
        foreach (['f-a', 'f-b', 'f-c', 'f-ok'] as $uuid) {
            $this->queue->enqueue('contact.sync', [], null, 0, $uuid);
        }
        $reasons = ['f-a' => 'recipient rejected', 'f-b' => 'mailbox full', 'f-c' => 'recipient rejected'];

        $writes = $this->failureWrites(fn () => $this->runCron(['contact.sync' => new RecordingHandler(
            static function (array $events) use ($reasons): array {
                $results = [];
                foreach ($events as $event) {
                    $results[(int)$event->getId()] = $reasons[$event->getEventUuid()] ?? true;
                }

                return $results;
            }
        )]));

        self::assertSame(2, $writes, 'One write per reason');
        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        foreach ($reasons as $uuid => $reason) {
            self::assertSame(Event::STATUS_PENDING, $rows[$uuid]['status'], $uuid);
            self::assertSame('1', (string)$rows[$uuid]['attempts'], $uuid);
            self::assertSame($reason, $rows[$uuid]['last_error'], $uuid);
        }
        self::assertSame(Event::STATUS_SENT, $rows['f-ok']['status']);
        self::assertNull($rows['f-ok']['last_error']);
    }

    /**
     * PRO-1964 with PRO-3753: the contacts of a 203 group go again one by
     * one, and each refused contact keeps its own answer — two refused
     * with the same answer are written together, each with its own
     * request body.
     */
    public function testContactsSentAloneAfterA203KeepTheirOwnAnswers(): void
    {
        $this->enqueueContacts(4);

        $writes = $this->failureWrites(fn () => $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":203,"message":"Invalid data"}'),
            new Response(200, [], '{"code":203,"message":"Invalid email"}'),
            new Response(200, [], '{"code":101,"message":"OK"}'),
            new Response(200, [], '{"code":203,"message":"Invalid email"}'),
            new Response(200, [], '{"code":203,"message":"Invalid phone"}'),
        ])]));

        self::assertSame(2, $writes, 'The two "Invalid email" rows together, the other alone');
        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_SENT, $rows['f-c2']['status']);
        $answers = ['f-c1' => 'Invalid email', 'f-c3' => 'Invalid email', 'f-c4' => 'Invalid phone'];
        foreach ($answers as $uuid => $message) {
            $row = $rows[$uuid];
            self::assertSame(Event::STATUS_FAILED, $row['status'], $uuid);
            self::assertSame('1', (string)$row['attempts'], $uuid);
            self::assertNull($row['next_retry_at'], $uuid);
            self::assertSame('permanent_envelope_203: Smaily API returned code 203: ' . $message, $row['last_error']);
            self::assertSame(
                $this->contacts((int)substr($uuid, 3)),
                json_decode((string)$row['sent_payload'], true),
                $uuid . ' keeps only its own contact'
            );
            self::assertSame(
                ['http_status' => 200, 'body' => ['code' => 203, 'message' => $message]],
                json_decode((string)$row['last_response'], true)
            );
        }
    }

    /**
     * How many statements recorded a failed attempt while $run ran: the
     * UPDATEs of the queue table that set last_error and a failed
     * outcome (a claim sets no error, a delivery no failed status).
     */
    private function failureWrites(callable $run): int
    {
        $connection = $this->connection;
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            self::fail('The test connection keeps no query profile');
        }
        $profiler = $connection->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $run();
        } finally {
            $profiler->setEnabled(false);
        }

        $writes = 0;
        foreach ($profiler->getQueryProfiles() ?: [] as $profile) {
            $query = $profile->getQuery();
            $failed = str_contains($query, 'CASE')
                || array_intersect([Event::STATUS_PENDING, Event::STATUS_FAILED], $profile->getQueryParams());
            if (str_starts_with($query, 'UPDATE `' . EventResource::TABLE_NAME . '`')
                && str_contains($query, '`last_error`')
                && $failed
            ) {
                $writes++;
            }
        }
        $profiler->clear();

        return $writes;
    }

    public function testAnotherErrorEnvelopeOnAGroupKeepsTheGroupOnTheLadder(): void
    {
        $this->enqueueContacts(5);

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":216,"message":"Unknown error"}'),
        ])]);

        self::assertCount(1, $this->requests, 'Only a 203 sends the contacts alone');
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_PENDING, $row['status']);
            self::assertSame('1', (string)$row['attempts']);
            self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
            self::assertSame('Smaily API returned code 216: Unknown error', $row['last_error']);
        }
    }

    public function testAnotherErrorEnvelopeKeepsTheLadder(): void
    {
        $this->enqueueContact('f-other-envelope');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":216,"message":"Unknown error"}'),
        ])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
        self::assertSame('Smaily API returned code 216: Unknown error', $row['last_error']);
    }

    public function testASlowDownParksTheRowForExactlyTheRequestedTime(): void
    {
        $this->enqueueContact('f-429');

        $handler = $this->realContactSync([new Response(429, ['Retry-After' => '90'], 'Slow down')]);
        $this->runCron(['contact.sync' => $handler]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status'], 'A slow-down is temporary, never terminal');
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame($this->clockDate(90), $row['next_retry_at']);
    }

    public function testASlowDownWithoutARetryAfterFallsBackToTheLadder(): void
    {
        $this->enqueueContact('f-429-bare');

        $this->runCron(['contact.sync' => $this->realContactSync([new Response(429, [], 'Slow down')])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
    }

    public function testServerErrorsKeepClimbingTheLadder(): void
    {
        $this->enqueueContact('f-503');
        $handler = $this->realContactSync([
            new Response(503, [], 'Service unavailable'),
            new Response(503, [], 'Service unavailable'),
        ]);

        $this->runCron(['contact.sync' => $handler]);
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);

        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->runCron(['contact.sync' => $handler]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertSame('2', (string)$row['attempts']);
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[1]), $row['next_retry_at']);
    }

    public function testATransportErrorWithoutAStatusStaysOnTheLadder(): void
    {
        $this->enqueueContact('f-timeout');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new ConnectException('cURL error 28: timed out', new Request('POST', 'api/contact.php')),
        ])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status'], 'No status to classify on: retry, never give up early');
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
    }

    public function testADeliveredRowRecordsThePayloadAsSentAndTheResponse(): void
    {
        $this->enqueueContact('f-evidence');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":101,"message":"OK"}'),
        ])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status']);
        self::assertSame(
            [['email' => 'a@example.com']],
            json_decode((string)$row['sent_payload'], true),
            'This row\'s part of the request body, exactly as it was posted'
        );
        self::assertSame(
            ['http_status' => 200, 'body' => ['code' => 101, 'message' => 'OK']],
            json_decode((string)$row['last_response'], true)
        );
    }

    public function testARefusedRowRecordsWhatTheServerAnswered(): void
    {
        $this->enqueueContact('f-refused-evidence');

        $this->runCron(['contact.sync' => $this->realContactSync([new Response(404, [], 'Not found')])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_FAILED, $row['status']);
        self::assertSame([['email' => 'a@example.com']], json_decode((string)$row['sent_payload'], true));
        self::assertSame(
            ['http_status' => 404, 'body' => 'Not found'],
            json_decode((string)$row['last_response'], true)
        );
    }

    public function testARetryThatGetsNoAnswerKeepsTheLastResponseReceived(): void
    {
        $this->enqueueContact('f-retry-evidence');
        $handler = $this->realContactSync([
            new Response(503, [], 'Service unavailable'),
            new ConnectException('cURL error 28: timed out', new Request('POST', 'api/contact.php')),
        ]);

        $this->runCron(['contact.sync' => $handler]);
        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0]);
        $this->runCron(['contact.sync' => $handler]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame('2', (string)$row['attempts']);
        self::assertStringContainsString('timed out', (string)$row['last_error']);
        self::assertSame([['email' => 'a@example.com']], json_decode((string)$row['sent_payload'], true));
        self::assertSame(
            ['http_status' => 503, 'body' => 'Service unavailable'],
            json_decode((string)$row['last_response'], true),
            'PRO-1963: the attempt without an answer does not erase the answer before it'
        );
    }

    /**
     * PRO-3619: Smaily creates a contact sent without a status as
     * subscribed, so the purchase marker goes only to a contact Smaily has.
     * The queue reads the contact first; for an address Smaily does not have
     * nothing is posted, and the row is closed with the reason.
     */
    public function testAPurchaseMarkerForAnAddressSmailyDoesNotHaveIsSkippedWithTheReason(): void
    {
        $this->enqueueMarker('f-marker-unknown');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":206,"message":"Could not find requested email address"}'),
        ])]);

        self::assertSame(['GET'], $this->requestMethods(), 'One read, and nothing posted');
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status'], 'Closed: retrying cannot change the answer');
        self::assertNull($row['next_retry_at']);
        self::assertNull($row['sent_payload'], 'Nothing was sent for this row');
        self::assertStringContainsString('Skipped', (string)$row['last_error']);
        self::assertStringContainsString('Smaily does not have this contact', (string)$row['last_error']);
    }

    /**
     * PRO-3634: an automation trigger with no Smaily workflow mapped is
     * closed without a request, and the Log reads it as Skipped with the
     * reason — not as delivered.
     */
    public function testAnAutomationWithNoWorkflowMappedIsClosedAsSkipped(): void
    {
        $this->queue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            ['trigger_type' => 'welcome', 'store_id' => 1, 'website_id' => 1, 'language' => 'en',
                'address' => ['email' => 'a@example.com']],
            'a@example.com',
            1,
            'f-automation-unmapped'
        );
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(null);
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $this->runCron([EventType::AUTOMATION_TRIGGER => $this->objectManager->create(AutomationHandler::class, [
            'router' => $router,
            'clientProvider' => $clientProvider,
            'accountResolver' => $this->createMock(AccountResolver::class),
        ])]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status'], 'Closed: retrying cannot make a mapping appear');
        self::assertNull($row['next_retry_at']);
        self::assertNull($row['sent_payload'], 'Nothing was sent for this row');
        self::assertSame(AutomationHandler::SKIPPED_NO_WORKFLOW, $row['last_error']);
        /** @var QueueRowLoader $rowLoader */
        $rowLoader = $this->objectManager->create(QueueRowLoader::class);
        self::assertSame(
            Collection::STATUS_SKIPPED,
            $rowLoader->load(Collection::SOURCE_SMAILY . '-' . $row['id'])['status'] ?? null
        );
    }

    public function testAPurchaseMarkerForAContactSmailyHasIsPosted(): void
    {
        $this->enqueueMarker('f-marker-known');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"email":"a@example.com","is_unsubscribed":"0"}'),
            new Response(200, [], '{"code":101,"message":"OK"}'),
        ])]);

        self::assertSame(['GET', 'POST'], $this->requestMethods());
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status']);
        self::assertNull($row['last_error']);
        self::assertSame(
            [['email' => 'a@example.com', Trigger::ABANDONED_CART_PURCHASED_FIELD => '2026-10-02 10:00:00']],
            json_decode((string)$row['sent_payload'], true)
        );
    }

    public function testAPurchaseMarkerWaitsWhenSmailyCannotBeRead(): void
    {
        $this->enqueueMarker('f-marker-unread');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(503, [], 'Service unavailable'),
        ])]);

        self::assertSame(['GET'], $this->requestMethods(), 'Nothing is posted while the answer is not known');
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status'], 'Retried on the ladder');
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame($this->clockDate(EventQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
    }

    public function testAContactSyncIsPostedWithoutAContactRead(): void
    {
        $this->enqueueContact('f-plain');

        $this->runCron(['contact.sync' => $this->realContactSync([
            new Response(200, [], '{"code":101,"message":"OK"}'),
        ])]);

        self::assertSame(['POST'], $this->requestMethods());
    }

    private function enqueueMarker(string $uuid): void
    {
        $this->queue->enqueue(
            'contact.sync',
            ['store_id' => 0, 'contact' => [
                'email' => 'a@example.com',
                Trigger::ABANDONED_CART_PURCHASED_FIELD => '2026-10-02 10:00:00',
            ]],
            'a@example.com',
            0,
            $uuid
        );
    }

    /**
     * @return string[]
     */
    private function requestMethods(): array
    {
        return array_map(static fn (array $entry): string => $entry['request']->getMethod(), $this->requests);
    }

    private function enqueueContact(string $uuid): void
    {
        $this->queue->enqueue(
            'contact.sync',
            ['store_id' => 0, 'contact' => ['email' => 'a@example.com']],
            null,
            0,
            $uuid
        );
    }

    private function enqueueContacts(int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $payload = ['store_id' => 0, 'contact' => $this->contacts($n)[0]];
            $this->queue->enqueue('contact.sync', $payload, null, 0, 'f-c' . $n);
        }
    }

    /**
     * @return list<array{email: string}>
     */
    private function contacts(int ...$numbers): array
    {
        return array_map(static fn (int $n): array => ['email' => 'u' . $n . '@example.invalid'], $numbers);
    }

    /**
     * @return mixed the JSON body of the request the fake transport received at $index
     */
    private function postedBody(int $index): mixed
    {
        return json_decode((string)$this->requests[$index]['request']->getBody(), true);
    }

    /**
     * The real contact-sync handler and a real SmailyClient, with only the
     * HTTP transport faked: statuses and Retry-After headers are parsed by
     * the shipping code, not stubbed past.
     *
     * @param array<int, Response|\Throwable> $responses
     */
    private function realContactSync(array $responses): ContactSyncHandler
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->requests));
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack): HttpClient {
                $config['handler'] = $handlerStack;
                return new HttpClient($config);
            }
        );

        /** @var Logger $logger */
        $logger = $this->objectManager->get(Logger::class);
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn(
            new SmailyClient(
                $httpClientFactory,
                $logger,
                $this->createMock(VerifiedCredentials::class),
                'demo',
                'user',
                'secret'
            )
        );

        /** @var ContactSyncHandler $handler */
        $handler = $this->objectManager->create(ContactSyncHandler::class, ['clientProvider' => $clientProvider]);

        return $handler;
    }

    /**
     * @param array<string, EventHandlerInterface> $handlers
     */
    private function runCron(array $handlers): void
    {
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool($handlers),
        ]);
        $cron->execute();
    }
}
