<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\MergeCustomerSender;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-262 (contract v1.23.0 §4 group c, §7 "Send the customer first" and
 * "Retry after 404") against a real database: both queues and both crons
 * are real, the engine is a fake that knows a customer only once §4 took
 * them, as the real one does. The marketing event queue's cron
 * (smaily_flush_event_queue) is declared before the ingest queue's in the
 * same cron group, so a merge row is sent before a customer row queued at
 * the same time: these tests run it first.
 */
class LoginCustomerFirstTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private const CUSTOMER_ID = 42;
    private const EMAIL = 'person@example.com';
    private const MERGE = [
        'customer_email' => self::EMAIL,
        'customer_external_id' => '42',
        'merge_ts' => '2026-11-02T10:00:00Z',
        'merge_reason' => 'user_logged_in',
        'store_id' => 1,
        'smaily_visitor_token' => 'vs_4fK9a2LmQ7xZ0bR3tY8wP1',
    ];

    private EventQueue $events;
    private IngestQueue $ingest;
    private Client&MockObject $client;

    /** @var string[] the engine calls in order */
    private array $calls = [];

    /** @var array<string, true> the customers the fake engine has */
    private array $known = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = $this->objectManager->create(EventQueue::class);
        $this->ingest = $this->objectManager->create(IngestQueue::class);
        $this->calls = [];
        $this->known = [];
        $this->client = $this->engine();
    }

    /**
     * A consenting shopper who has just registered: the customer save
     * queued the customer row, the login the merge. The merge's run sends
     * the customer first, so the merge binds although the ingest cron has
     * not run yet; the Log keeps the customer it sent as a delivered row.
     */
    public function testAConsentingLoginSendsTheCustomerBeforeTheMergeWhateverTheCronOrder(): void
    {
        $this->queueCustomerRow('reg-row');
        $this->queueMerge([IdentityMergeHandler::SEND_CUSTOMER => true]);

        $this->flushEvents();

        self::assertSame(['customers', 'merge'], $this->calls);
        $merge = $this->mergeRow();
        self::assertSame(Event::STATUS_SENT, $merge['status']);
        self::assertNull($merge['last_error']);
        $sent = json_decode((string)$merge['sent_payload'], true);
        self::assertIsArray($sent);
        self::assertArrayNotHasKey(IdentityMergeHandler::SEND_CUSTOMER, $sent, 'The flag is the store\'s own');
        self::assertArrayNotHasKey('store_id', $sent);

        $rows = $this->customerRows();
        self::assertCount(2, $rows);
        self::assertSame(IngestEvent::STATUS_PENDING, $rows[0]['status'], 'The live row is the ingest cron\'s');
        self::assertSame(IngestEvent::STATUS_SENT, $rows[1]['status'], 'The customer sent at login, in the Log');
        self::assertSame('42', $rows[1]['entity_id']);
        $item = json_decode((string)$rows[1]['sent_payload'], true);
        self::assertIsArray($item);
        self::assertSame($rows[1]['event_uuid'], $item['event_id']);

        // The ingest cron sends the live row afterwards: one more upsert of
        // the same customer, never a removal. Nothing in the live sync or
        // the customers import takes a group (c) customer back out.
        $this->flushIngest();

        self::assertSame(['customers', 'merge', 'customers'], $this->calls);
        self::assertSame(
            [IngestEvent::STATUS_SENT, IngestEvent::STATUS_SENT],
            array_column($this->customerRows(), 'status')
        );
        self::assertSame(Event::STATUS_SENT, $this->mergeRow()['status']);
    }

    /**
     * Without consent nothing is sent at the login itself; a merge that
     * meets the race (its customer row still queued) answers 404, the
     * customer is sent, and the merge is retried once — in the same run.
     */
    public function testAMergeThatMeetsTheRaceIsRetriedOnceAfterTheCustomerIsSent(): void
    {
        $this->queueCustomerRow('reg-row');
        $this->queueMerge();

        $this->flushEvents();

        self::assertSame(['merge', 'customers', 'merge'], $this->calls);
        self::assertSame(Event::STATUS_SENT, $this->mergeRow()['status']);
    }

    /**
     * No retry before §4 answered 200: while the engine cannot take the
     * customer the row climbs the ladder; its next attempt sends the
     * customer before it merges.
     */
    public function testTheMergeWaitsForTheCustomerAcrossAttempts(): void
    {
        $this->queueCustomerRow('reg-row');
        $this->queueMerge();
        $failSends = 1;
        $this->client = $this->engine($failSends);

        $this->flushEvents();

        self::assertSame(['merge', 'customers'], $this->calls);
        $merge = $this->mergeRow();
        self::assertSame(Event::STATUS_PENDING, $merge['status']);
        self::assertSame('1', (string)$merge['attempts']);
        self::assertTrue(
            json_decode((string)$merge['payload'], true)[IdentityMergeHandler::CUSTOMER_NOT_FOUND] ?? false,
            'The row remembers the 404'
        );

        $this->connection->update(
            $this->connection->getTableName(EventResource::TABLE_NAME),
            ['next_retry_at' => null]
        );
        $this->calls = [];
        $this->flushEvents();

        self::assertSame(['customers', 'merge'], $this->calls);
        self::assertSame(Event::STATUS_SENT, $this->mergeRow()['status']);
    }

    /**
     * A customer the store does not send (no consent, never bought, not in
     * the contact sync): the row is parked with the reason, not retried
     * forever. Once the customer reaches the engine, Retry in the Log
     * sends the customer the store now sends and links the login.
     */
    public function testAMergeForACustomerTheStoreDoesNotSendIsParkedAndRecoversOnRetry(): void
    {
        $this->queueMerge();

        $this->flushEvents();

        self::assertSame(['merge'], $this->calls);
        $merge = $this->mergeRow();
        self::assertSame(Event::STATUS_FAILED, $merge['status']);
        self::assertSame(IdentityMergeHandler::NOT_SENT, $merge['last_error']);

        // The customer buys: the order sync queues the customer row, which
        // the ingest cron delivers.
        $this->queueCustomerRow('later-row');
        $this->flushIngest();
        self::assertSame(1, $this->events->retry([(int)$merge['id']]));
        $this->calls = [];

        $this->flushEvents();

        self::assertSame(['customers', 'merge'], $this->calls, 'Customer first: the row met a 404 before');
        self::assertSame(Event::STATUS_SENT, $this->mergeRow()['status']);
    }

    /**
     * A row parked by a 404 before CON-262 carries no flag, only the
     * engine's answer: it too sends the customer before its merge on Retry.
     */
    public function testARowParkedByA404BeforeTheChangeRecoversOnRetry(): void
    {
        $this->queueCustomerRow('reg-row');
        $id = $this->queueMerge();
        $this->connection->update(
            $this->connection->getTableName(EventResource::TABLE_NAME),
            [
                'status' => Event::STATUS_FAILED,
                'attempts' => 1,
                'last_error' => 'permanent_http_404: Engine request failed with HTTP 404: No customer found',
                'last_response' => json_encode([
                    'http_status' => 404,
                    'body' => ['error' => 'customer_not_found', 'message' => 'No customer found'],
                ]),
            ],
            ['id = ?' => $id]
        );
        self::assertSame(1, $this->events->retry([$id]));

        $this->flushEvents();

        self::assertSame(['customers', 'merge'], $this->calls);
        self::assertSame(Event::STATUS_SENT, $this->mergeRow()['status']);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function queueMerge(array $extra = []): int
    {
        $this->events->enqueue(EventType::ENGINE_IDENTITY_MERGE, self::MERGE + $extra, '42', 1, 'merge-row');

        return (int)$this->mergeRow()['id'];
    }

    private function queueCustomerRow(string $uuid): void
    {
        $this->ingest->enqueue(
            Client::DOMAIN_CUSTOMERS,
            ['email' => self::EMAIL, 'external_id' => '42'],
            '42',
            1,
            $uuid
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mergeRow(): array
    {
        $rows = $this->fetchAll($this->connection->getTableName(EventResource::TABLE_NAME));
        self::assertCount(1, $rows);

        return $rows[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customerRows(): array
    {
        return $this->fetchAll($this->connection->getTableName(IngestEventResource::TABLE_NAME));
    }

    /**
     * A fake engine: §4 makes a customer known (after $failSends failures
     * in transport), §7 answers 404 `customer_not_found` for an unknown one.
     */
    private function engine(int &$failSends = 0): Client&MockObject
    {
        $client = $this->createMock(Client::class);
        $client->method('ingest')->willReturnCallback(
            function (string $domain, array $items) use (&$failSends): array {
                self::assertSame(Client::DOMAIN_CUSTOMERS, $domain);
                $this->calls[] = 'customers';
                if ($failSends > 0) {
                    $failSends--;
                    throw new EngineTransportException(
                        'Engine request failed with HTTP 503 after retries',
                        503
                    );
                }
                foreach ($items as $item) {
                    $this->known[strtolower((string)$item['email'])] = true;
                }

                return ['ok' => true, 'processed' => count($items), 'deduplicated' => 0, 'errors' => []];
            }
        );
        $client->method('identityMerge')->willReturnCallback(function (array $payload): array {
            $this->calls[] = 'merge';
            if (!isset($this->known[$payload['customer_email']])) {
                throw new EngineRequestException(
                    'Engine request failed with HTTP 404: No customer found',
                    404,
                    ['error' => 'customer_not_found', 'message' => 'No customer found']
                );
            }

            return ['ok' => true, 'customer_id' => 'c-1', 'merged' => ['visitor_tokens_bound' => 1]];
        });
        $client->method('lastExchange')->willReturnCallback(fn (): array => [
            'request' => ['customer_email' => self::EMAIL],
            'response' => isset($this->known[self::EMAIL])
                ? ['http_status' => 200, 'body' => ['ok' => true]]
                : ['http_status' => 404, 'body' => ['error' => 'customer_not_found']],
        ]);
        $client->expects(self::never())->method('customerDelete');

        return $client;
    }

    private function flushEvents(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('isRefused')->willReturn(false);
        $settings->method('sendingBlockedReason')->willReturn(null);
        $consent = $this->createMock(ProfilingConsent::class);
        $consent->method('isAllowed')->willReturn(true);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(1);
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getById')->with(self::CUSTOMER_ID)->willReturn($customer);
        $builder = $this->createMock(CustomerPayloadBuilder::class);
        $builder->method('build')->willReturn(['email' => self::EMAIL, 'external_id' => '42']);

        $handler = $this->objectManager->create(IdentityMergeHandler::class, [
            'settings' => $settings,
            'client' => $this->client,
            'eventQueue' => $this->events,
            'profilingConsent' => $consent,
            'customerRepository' => $repository,
            'customerSender' => new MergeCustomerSender($repository, $builder, $this->client, $this->ingest),
            'ingestQueue' => $this->ingest,
            'connections' => $this->oneEngineConnection($settings, $this->client),
        ]);
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'eventQueue' => $this->events,
            'handlerPool' => new HandlerPool([EventType::ENGINE_IDENTITY_MERGE => $handler]),
        ]);
        $cron->execute();
    }

    private function flushIngest(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(true);
        /** @var Logger $logger */
        $logger = $this->objectManager->get(Logger::class);
        /** @var Json $serializer */
        $serializer = $this->objectManager->get(Json::class);

        (new FlushIngestQueue(
            $this->oneEngineConnection($settings, $this->client),
            $this->ingest,
            $this->createMock(CatalogIngest::class),
            $this->createMock(OptOutReplay::class),
            $serializer,
            $logger,
            new IngestPace(),
            $this->objectManager->create(JobManager::class)
        ))->execute();
    }
}
