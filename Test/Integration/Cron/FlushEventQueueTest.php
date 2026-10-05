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

    private function identityMerge(\Throwable $failure): IdentityMergeHandler
    {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isConnected')->willReturn(true);
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
