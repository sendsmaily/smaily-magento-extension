<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * The engine ingest flush cron against a real queue table with the HTTP
 * client mocked: D6 per-item error mapping, transport vs request failures.
 */
class FlushIngestQueueTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private IngestQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(IngestQueue::class);
    }

    /**
     * CON-357: a refused row keeps the field, never the engine's message,
     * which can name the customer.
     */
    public function testD6ResponseMapsPerItemErrorsOntoBatchRows(): void
    {
        $this->queue->enqueue('catalog', ['sku' => 'OK-1'], null, null, 'fi-1');
        $this->queue->enqueue('catalog', ['sku' => 'BAD'], null, null, 'fi-2');
        $this->queue->enqueue('catalog', ['sku' => 'OK-2'], null, null, 'fi-3');

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with('catalog', self::callback(static fn (array $items): bool => count($items) === 3))
            ->willReturn([
                'processed' => 2,
                'deduplicated' => 0,
                'errors' => [[
                    'index' => 1,
                    'field' => 'price',
                    'message' => 'Mari Maasikas (mari@example.com): must be a number',
                ]],
            ]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fi-1']['status']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fi-3']['status']);
        self::assertSame(IngestEvent::STATUS_FAILED, $rows['fi-2']['status'], 'Per-item errors are terminal');
        self::assertSame('price: rejected by engine', $rows['fi-2']['last_error']);
        self::assertNull($rows['fi-2']['next_retry_at']);
    }

    /**
     * CON-241: the job of an import counts the rows the engine accepted —
     * not a row it refused, not an order it skipped as older than the
     * horizon; a job from before the count began keeps none.
     */
    public function testAnImportJobCountsTheRowsTheEngineAccepted(): void
    {
        $jobManager = $this->objectManager->create(JobManager::class);
        $job = $jobManager->start('orders', 'engine', 0);
        $jobId = (int)$job->getId();
        $old = $jobManager->start('customers', 'engine', 0);
        $this->connection->update('smaily_backfill_job', ['sent_count' => null], ['id = ?' => (int)$old->getId()]);
        foreach (['o-1', 'o-2', 'o-3', 'o-4'] as $uuid) {
            $this->queue->enqueue('orders', ['external_order_id' => $uuid], null, null, $uuid, $jobId);
        }
        $this->queue->enqueue('orders', ['external_order_id' => 'live'], null, null, 'live');
        $this->queue->enqueue('customers', ['email' => 'c@example.invalid'], null, null, 'c-1', (int)$old->getId());

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willReturnCallback(static fn (string $domain, array $items): array => [
            'processed' => count($items) - ($domain === 'orders' ? 2 : 0),
            'deduplicated' => 0,
            'errors' => $domain === 'orders' ? [['index' => 1, 'field' => 'status', 'message' => 'invalid']] : [],
            'skipped' => $domain === 'orders' ? 1 : 0,
            'skipped_orders' => $domain === 'orders'
                ? [['index' => 3, 'external_order_id' => 'o-4', 'reason' => 'older_than_retention']]
                : [],
        ]);
        $this->runCron($client);

        self::assertSame(2, $jobManager->get($jobId)?->getSentCount());
        self::assertNull($jobManager->get((int)$old->getId())?->getSentCount());
        self::assertSame(0, $this->queue->countUndelivered([$jobId]));
    }

    /**
     * PRO-1965: each row keeps its own item as sent, in the wrapper's shape,
     * and the engine's reply as it concerns that row — no other row's error.
     */
    public function testEachRowRecordsItsItemAsSentAndItsShareOfTheReply(): void
    {
        $this->queue->enqueue('catalog', ['sku' => 'OK-1'], null, null, 'fi-e1');
        $this->queue->enqueue('catalog', ['sku' => 'BAD'], null, null, 'fi-e2');
        $reply = [
            'processed' => 1,
            'deduplicated' => 0,
            'errors' => [['index' => 1, 'field' => 'price', 'message' => 'must be a number']],
        ];

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willReturn($reply);
        $client->method('lastExchange')->willReturn([
            'request' => ['products' => [['sku' => 'OK-1'], ['sku' => 'BAD']]],
            'response' => ['http_status' => 200, 'body' => $reply],
        ]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(
            ['products' => [['sku' => 'OK-1', 'event_id' => 'fi-e1']]],
            json_decode((string)$rows['fi-e1']['sent_payload'], true)
        );
        self::assertSame(
            ['http_status' => 200, 'body' => ['processed' => 1, 'deduplicated' => 0, 'errors' => []]],
            json_decode((string)$rows['fi-e1']['last_response'], true)
        );
        self::assertSame(
            ['products' => [['sku' => 'BAD', 'event_id' => 'fi-e2']]],
            json_decode((string)$rows['fi-e2']['sent_payload'], true)
        );
        self::assertSame(
            ['http_status' => 200, 'body' => $reply],
            json_decode((string)$rows['fi-e2']['last_response'], true)
        );
    }

    public function testARetryThatGetsNoAnswerKeepsTheLastReplyReceived(): void
    {
        $this->queue->enqueue('orders', ['order' => []], null, null, 'fi-keep');

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineTransportException('HTTP 503'));
        $client->method('lastExchange')->willReturnOnConsecutiveCalls(
            ['request' => [], 'response' => ['http_status' => 503, 'body' => ['error' => 'unavailable']]],
            ['request' => [], 'response' => null]
        );
        $this->runCron($client);
        $this->clock->travel(IngestQueue::BACKOFF_SECONDS[0]);
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame('2', (string)$row['attempts']);
        self::assertSame(
            ['http_status' => 503, 'body' => ['error' => 'unavailable']],
            json_decode((string)$row['last_response'], true),
            'PRO-1963: the attempt without an answer does not erase the answer before it'
        );
    }

    public function testADeliveredRowKeepsTheReplyItGot(): void
    {
        $this->queue->enqueue('orders', ['order' => []], null, null, 'fi-sent');

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willReturn(['processed' => 1]);
        $client->method('lastExchange')->willReturn(
            ['request' => [], 'response' => ['http_status' => 200, 'body' => ['processed' => 1]]]
        );
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_SENT, $row['status']);
        self::assertSame(
            ['http_status' => 200, 'body' => ['processed' => 1]],
            json_decode((string)$row['last_response'], true),
            'markSent() without a response of its own does not erase the recorded one'
        );
    }

    public function testTransportFailureReschedulesTheBatchWithBackoff(): void
    {
        $this->queue->enqueue('orders', ['order' => []], null, null, 'fi-t1');

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineTransportException('HTTP 503'));
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame($this->clockDate(IngestQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
    }

    public function testWholeBatchRequestErrorIsTerminal(): void
    {
        $this->queue->enqueue('customers', ['customer' => []], null, null, 'fi-r1');

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineRequestException('HTTP 400: bad wrapper', 400));
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status'], 'Malformed requests must not be retried');
        self::assertNull($row['next_retry_at']);
    }

    /**
     * §3b catalog/remove rows (PRO-1231) flush through their own non-D6
     * path: unique ids in one wrapper, per-row outcome from `not_found`
     * (a contract-defined success), keyless rows parked observably, and
     * the D6 domain loop never consumes them.
     */
    public function testCatalogRemoveRowsFlushAsOneSection3bWrapper(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '7'], '7', null, 'fr-1');
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '9'], '9', null, 'fr-2');
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, [], null, null, 'fr-3');

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('ingest');
        $client->expects(self::once())->method('catalogRemove')
            ->with(['7', '9'])
            ->willReturn(['ok' => true, 'removed_products' => 1, 'rows_tombstoned' => 2, 'not_found' => ['9']]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fr-1']['status']);
        self::assertStringContainsString('"outcome":"removed"', (string)$rows['fr-1']['last_response']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fr-2']['status'], 'not_found is a success, never a retry');
        self::assertStringContainsString('"outcome":"not_found"', (string)$rows['fr-2']['last_response']);
        self::assertSame(IngestEvent::STATUS_FAILED, $rows['fr-3']['status'], 'Keyless rows park observably');
        self::assertSame('catalog/remove row has no product_id', $rows['fr-3']['last_error']);
    }

    public function testACatalogRemoveRowRecordsItsIdAsSentAndTheRefusal(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '7'], '7', null, 'fr-e1');

        $client = $this->createMock(Client::class);
        $client->method('catalogRemove')->willThrowException(new EngineRequestException('HTTP 422', 422));
        $client->method('lastExchange')->willReturn([
            'request' => ['product_ids' => ['7']],
            'response' => ['http_status' => 422, 'body' => ['error' => 'validation_failed']],
        ]);
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(['product_ids' => ['7']], json_decode((string)$row['sent_payload'], true));
        self::assertSame(
            ['http_status' => 422, 'body' => ['error' => 'validation_failed']],
            json_decode((string)$row['last_response'], true)
        );
    }

    public function testCatalogRemoveTransportFailureReschedulesWithBackoff(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '7'], '7', null, 'fr-t1');

        $client = $this->createMock(Client::class);
        $client->method('catalogRemove')->willThrowException(new EngineTransportException('HTTP 503'));
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame($this->clockDate(IngestQueue::BACKOFF_SECONDS[0]), $row['next_retry_at']);
    }

    public function testCatalogRemoveRequestErrorParksTheRowsTerminally(): void
    {
        // A 404 = the engine predates §3b; the row is parked.
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '7'], '7', null, 'fr-r1');

        $client = $this->createMock(Client::class);
        $client->method('catalogRemove')->willThrowException(new EngineRequestException('HTTP 404: not found', 404));
        $this->runCron($client);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status']);
        self::assertNull($row['next_retry_at']);
    }

    /**
     * Contract §2 / PRO-2451: rows met by a refused account go back to
     * pending exactly as they were — status, attempts, backoff and error
     * untouched — and wait for the account to be active again.
     */
    public function testARefusedAccountLeavesTheClaimedRowsPending(): void
    {
        $this->queue->enqueue('catalog', ['sku' => 'A'], null, null, 'fi-ref1');
        $this->queue->enqueue('catalog', ['sku' => 'B'], null, null, 'fi-ref2');

        $account = new class {
            public bool $refused = false;
        };
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturnCallback(
            static fn (): bool => !$account->refused
        );
        $settings->method('isRefused')->willReturnCallback(
            static fn (): bool => $account->refused
        );
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')->willReturnCallback(
            static function () use ($account): array {
                $account->refused = true;

                throw new EngineRequestException('HTTP 403: tenant_inactive', 403);
            }
        );

        $this->buildCron($settings, $client)->execute();

        foreach ($this->fetchAll(IngestEventResource::TABLE_NAME) as $row) {
            self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
            self::assertSame('0', (string)$row['attempts'], 'A verdict never burns an attempt');
            self::assertNull($row['next_retry_at']);
            self::assertNull($row['last_error']);
            self::assertNull($row['claim_token']);
        }
    }

    public function testDisconnectedEngineLeavesTheQueueUntouched(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'fi-idle');

        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(false);
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('ingest');

        $this->buildCron($settings, $client)->execute();

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
    }

    /**
     * PRO-3962: a batch the engine does not take is recorded in one UPDATE,
     * not one per row — and each row still ends on its own step of the
     * ladder, with its own item as sent.
     */
    public function testABatchFailureIsRecordedInOneWriteWithEachRowOnItsOwnStep(): void
    {
        $this->enqueueCatalog(5);
        // fi-g5 failed three times before: this failure is its fourth.
        $this->connection->update(IngestEventResource::TABLE_NAME, ['attempts' => 3], ['event_uuid = ?' => 'fi-g5']);

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineTransportException('HTTP 503'));
        $client->method('lastExchange')->willReturn(
            ['request' => [], 'response' => ['http_status' => 503, 'body' => ['error' => 'unavailable']]]
        );
        $writes = $this->failureWrites(fn () => $this->runCron($client));

        self::assertSame(1, $writes, 'One statement for the five rows');
        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        foreach ([1, 2, 3, 4, 5] as $n) {
            $row = $rows['fi-g' . $n];
            self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
            self::assertSame($n === 5 ? '4' : '1', (string)$row['attempts']);
            self::assertSame(
                $this->clockDate(IngestQueue::BACKOFF_SECONDS[$n === 5 ? 3 : 0]),
                $row['next_retry_at'],
                'Each row on its own step of the ladder'
            );
            self::assertSame('HTTP 503', $row['last_error']);
            self::assertSame(
                ['sku' => 'SKU-' . $n, 'event_id' => 'fi-g' . $n],
                json_decode((string)$row['sent_payload'], true)['products'][0],
                'Each row keeps its own item as sent'
            );
            self::assertSame(
                ['http_status' => 503, 'body' => ['error' => 'unavailable']],
                json_decode((string)$row['last_response'], true)
            );
        }
    }

    /**
     * PRO-3962: a batch failure that reaches the last step parks the rows
     * whose attempts are spent and reschedules the others, in the same
     * write.
     */
    public function testABatchFailureParksOnlyTheRowsWhoseAttemptsAreSpent(): void
    {
        $this->enqueueCatalog(3);
        $this->connection->update(
            IngestEventResource::TABLE_NAME,
            ['attempts' => IngestQueue::MAX_ATTEMPTS - 1],
            ['event_uuid = ?' => 'fi-g2']
        );

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineTransportException('HTTP 503'));
        $writes = $this->failureWrites(fn () => $this->runCron($client));

        self::assertSame(1, $writes);
        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_FAILED, $rows['fi-g2']['status']);
        self::assertSame((string)IngestQueue::MAX_ATTEMPTS, (string)$rows['fi-g2']['attempts']);
        self::assertNull($rows['fi-g2']['next_retry_at']);
        foreach (['fi-g1', 'fi-g3'] as $uuid) {
            self::assertSame(IngestEvent::STATUS_PENDING, $rows[$uuid]['status']);
            self::assertSame('1', (string)$rows[$uuid]['attempts']);
            self::assertSame($this->clockDate(IngestQueue::BACKOFF_SECONDS[0]), $rows[$uuid]['next_retry_at']);
        }
    }

    /**
     * PRO-3962: a whole batch the engine refuses is parked in one write,
     * and so is a catalog/remove wrapper it refuses.
     */
    public function testARefusedBatchIsParkedInOneWrite(): void
    {
        $this->enqueueCatalog(3);
        foreach (['7', '8'] as $productId) {
            $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => $productId], $productId, null);
        }

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willThrowException(new EngineRequestException('HTTP 400: bad wrapper', 400));
        $client->method('catalogRemove')->willThrowException(new EngineRequestException('HTTP 404: not found', 404));
        $writes = $this->failureWrites(fn () => $this->runCron($client));

        self::assertSame(2, $writes, 'One write for the catalog batch, one for the catalog/remove wrapper');
        foreach ($this->fetchAll(IngestEventResource::TABLE_NAME) as $row) {
            self::assertSame(IngestEvent::STATUS_FAILED, $row['status']);
            self::assertSame('1', (string)$row['attempts']);
            self::assertNull($row['next_retry_at']);
            self::assertSame(
                $row['domain'] === Client::DOMAIN_CATALOG ? 'HTTP 400: bad wrapper' : 'HTTP 404: not found',
                $row['last_error']
            );
        }
    }

    /**
     * PRO-3962: the rows a D6 reply refuses keep their own reasons — one
     * write per reason, the rows of a shared reason together — and the
     * rows it takes are delivered.
     */
    public function testRowsRefusedForDifferentReasonsKeepTheirOwn(): void
    {
        $this->enqueueCatalog(5);

        $client = $this->createMock(Client::class);
        $client->method('ingest')->willReturn([
            'processed' => 2,
            'deduplicated' => 0,
            'errors' => [
                ['index' => 0, 'field' => 'price', 'message' => 'must be a number'],
                ['index' => 2, 'field' => 'price', 'message' => 'must be a number'],
                ['index' => 3, 'field' => 'sku', 'message' => 'required'],
            ],
        ]);
        $writes = $this->failureWrites(fn () => $this->runCron($client));

        self::assertSame(2, $writes, 'One write per reason');
        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        $reasons = [
            'fi-g1' => 'price: rejected by engine',
            'fi-g3' => 'price: rejected by engine',
            'fi-g4' => 'sku: rejected by engine',
        ];
        foreach ($reasons as $uuid => $reason) {
            self::assertSame(IngestEvent::STATUS_FAILED, $rows[$uuid]['status'], $uuid);
            self::assertSame('1', (string)$rows[$uuid]['attempts'], $uuid);
            self::assertNull($rows[$uuid]['next_retry_at'], $uuid);
            self::assertSame($reason, $rows[$uuid]['last_error'], $uuid);
        }
        foreach (['fi-g2', 'fi-g5'] as $uuid) {
            self::assertSame(IngestEvent::STATUS_SENT, $rows[$uuid]['status'], $uuid);
            self::assertNull($rows[$uuid]['last_error'], $uuid);
        }
    }

    /**
     * CON-423: a catalog row that failed waits for its retry while a later
     * row of the product goes. At its retry it is closed as skipped, never
     * sent: it would put the older stock back in the engine. Another
     * product's row in the batch goes.
     */
    public function testARetriedCatalogRowIsSkippedOnceALaterRowOfTheProductWent(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => false], '7', null, 's-old');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_CATALOG, 10)[0], 'HTTP 503');
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => true], '7', null, 's-new');
        $this->queue->markSent($this->queue->claimBatch(Client::DOMAIN_CATALOG, 10)[0]);
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P8', 'in_stock' => true], '8', null, 's-other');
        $this->clock->travel(IngestQueue::BACKOFF_SECONDS[0]);

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with(Client::DOMAIN_CATALOG, self::callback(
                static fn (array $items): bool => array_column($items, 'event_id') === ['s-other']
            ))
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['s-old']['status']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED, $rows['s-old']['last_error']);
        self::assertSame('1', (string)$rows['s-old']['attempts'], 'Closed without another attempt');
        self::assertNull($rows['s-old']['next_retry_at']);
        self::assertNull($rows['s-old']['claim_token']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['s-other']['status']);
        self::assertNull($rows['s-other']['last_error']);
    }

    /**
     * CON-423: of two rows of a product that come due together only the
     * later goes, whatever order the claim hands them in.
     */
    public function testOfTwoRowsOfAProductThatComeDueTogetherOnlyTheLaterGoes(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => true], '7', null, 'b-1');
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => false], '7', null, 'b-2');

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with(Client::DOMAIN_CATALOG, self::callback(
                static fn (array $items): bool => array_column($items, 'event_id') === ['b-2']
            ))
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED, $rows['b-1']['last_error']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['b-2']['status']);
        self::assertNull($rows['b-2']['last_error']);
    }

    /**
     * CON-423: a "Send again" row stands where the row it sends again
     * stood, so it is skipped once a row of the product later than that
     * one went — and with no batch left, nothing is sent at all.
     */
    public function testASendAgainOfAnOlderCatalogRowIsSkippedOnceALaterRowWent(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => false], '7', null, 'r-old');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_CATALOG, 10)[0], 'HTTP 400', true);
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => true], '7', null, 'r-new');
        $this->queue->markSent($this->queue->claimBatch(Client::DOMAIN_CATALOG, 10)[0]);
        $this->queue->enqueue(Client::DOMAIN_CATALOG, [
            'sku' => 'P7',
            'in_stock' => false,
            Resend::PAYLOAD_KEY => ['of' => 1, 'by' => 'admin', 'at' => '2026-11-26T10:00:00Z'],
        ], '7', null, 'r-again');

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('ingest');
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['r-again']['status']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED, $rows['r-again']['last_error']);
    }

    /**
     * CON-423: a later row that failed supersedes nothing — the retried
     * row is the newest the engine can still get, and goes.
     */
    public function testARetriedCatalogRowGoesWhenTheLaterRowOfTheProductFailed(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => false], '7', null, 'f-old');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_CATALOG, 10)[0], 'HTTP 503');
        $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'P7', 'in_stock' => true], '7', null, 'f-new');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_CATALOG, 10)[0], 'price: rejected', true);
        $this->clock->travel(IngestQueue::BACKOFF_SECONDS[0]);

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with(Client::DOMAIN_CATALOG, self::callback(
                static fn (array $items): bool => array_column($items, 'event_id') === ['f-old']
            ))
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['f-old']['status']);
        self::assertNull($rows['f-old']['last_error']);
    }

    /**
     * CON-448: an order row carries the whole order, as a catalog row the
     * whole product. A history import's row of an order that failed waits
     * for its retry while the order's refund row goes; at its retry it is
     * skipped, never sent: it would put the order back as it was before the
     * refund. Another order's row in the batch goes.
     */
    public function testARetriedOrderRowIsSkippedOnceALaterRowOfTheOrderWent(): void
    {
        $jobId = (int)$this->objectManager->create(JobManager::class)->start('orders', 'engine', 0)->getId();
        $this->queue->enqueue(Client::DOMAIN_ORDERS, [
            'external_order_id' => '100000007',
            'status' => 'complete',
        ], '100000007', null, 'o-old', $jobId);
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_ORDERS, 10)[0], 'HTTP 503');
        $this->queue->enqueue(Client::DOMAIN_ORDERS, [
            'external_order_id' => '100000007',
            'status' => 'refunded',
        ], '100000007', null, 'o-new');
        $this->queue->markSent($this->queue->claimBatch(Client::DOMAIN_ORDERS, 10)[0]);
        $this->queue->enqueue(Client::DOMAIN_ORDERS, ['external_order_id' => '100000008'], '100000008', null, 'o-other');
        $this->clock->travel(IngestQueue::BACKOFF_SECONDS[0]);

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with(Client::DOMAIN_ORDERS, self::callback(
                static fn (array $items): bool => array_column($items, 'event_id') === ['o-other']
            ))
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['o-old']['status']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED_ORDER, $rows['o-old']['last_error']);
        self::assertSame('1', (string)$rows['o-old']['attempts'], 'Closed without another attempt');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['o-other']['status']);
        self::assertNull($rows['o-other']['last_error']);
        self::assertSame(0, $this->queue->countUndelivered([$jobId]), 'The import does not wait for the row');
    }

    /**
     * CON-448: the same for a customer row. The queue names the customer by
     * the store's customer id, as the engine does when the email changed
     * (contract §4 Identity): an older row sent late would put the old
     * email back.
     */
    public function testARetriedCustomerRowIsSkippedOnceALaterRowOfTheCustomerWent(): void
    {
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, [
            'email' => 'old@example.invalid',
            'external_id' => '5',
        ], '5', null, 'c-old');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_CUSTOMERS, 10)[0], 'HTTP 503');
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, [
            'email' => 'new@example.invalid',
            'external_id' => '5',
        ], '5', null, 'c-new');
        $this->queue->markSent($this->queue->claimBatch(Client::DOMAIN_CUSTOMERS, 10)[0]);
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, [
            'email' => 'other@example.invalid',
            'external_id' => '6',
        ], '6', null, 'c-other');
        $this->clock->travel(IngestQueue::BACKOFF_SECONDS[0]);

        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('ingest')
            ->with(Client::DOMAIN_CUSTOMERS, self::callback(
                static fn (array $items): bool => array_column($items, 'event_id') === ['c-other']
            ))
            ->willReturn(['processed' => 1, 'deduplicated' => 0, 'errors' => []]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['c-old']['status']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED_CUSTOMER, $rows['c-old']['last_error']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['c-other']['status']);
        self::assertNull($rows['c-other']['last_error']);
    }

    /**
     * CON-448: of two rows of an order, or of a customer, that come due
     * together only the later goes.
     */
    public function testOfTwoRowsOfAnOrderOrACustomerThatComeDueTogetherOnlyTheLaterGoes(): void
    {
        $this->queue->enqueue(Client::DOMAIN_ORDERS, ['status' => 'processing'], '100000007', null, 'ob-1');
        $this->queue->enqueue(Client::DOMAIN_ORDERS, ['status' => 'complete'], '100000007', null, 'ob-2');
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, ['email' => 'a@example.invalid'], '5', null, 'cb-1');
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, ['email' => 'b@example.invalid'], '5', null, 'cb-2');

        $sent = [];
        $client = $this->createMock(Client::class);
        $client->method('ingest')->willReturnCallback(
            static function (string $domain, array $items) use (&$sent): array {
                $sent[$domain] = array_column($items, 'event_id');

                return ['processed' => count($items), 'deduplicated' => 0, 'errors' => []];
            }
        );
        $this->runCron($client);

        self::assertSame([Client::DOMAIN_CUSTOMERS => ['cb-2'], Client::DOMAIN_ORDERS => ['ob-2']], $sent);
        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED_ORDER, $rows['ob-1']['last_error']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED_CUSTOMER, $rows['cb-1']['last_error']);
    }

    /**
     * CON-448: the Log's "Retry" of an older order row and its "Send again"
     * of an older customer row follow the same rule once a later row went.
     */
    public function testTheLogsRetryAndSendAgainOfAnOlderOrderOrCustomerRowAreSkipped(): void
    {
        $this->queue->enqueue(Client::DOMAIN_ORDERS, ['status' => 'complete'], '100000007', null, 'ol-old');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_ORDERS, 10)[0], 'HTTP 400', true);
        $this->queue->enqueue(Client::DOMAIN_ORDERS, ['status' => 'closed'], '100000007', null, 'ol-new');
        $this->queue->markSent($this->queue->claimBatch(Client::DOMAIN_ORDERS, 10)[0]);
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, ['email' => 'old@example.invalid'], '5', null, 'cl-old');
        $this->queue->markFailed($this->queue->claimBatch(Client::DOMAIN_CUSTOMERS, 10)[0], 'HTTP 400', true);
        $this->queue->enqueue(Client::DOMAIN_CUSTOMERS, ['email' => 'new@example.invalid'], '5', null, 'cl-new');
        $this->queue->markSent($this->queue->claimBatch(Client::DOMAIN_CUSTOMERS, 10)[0]);

        $ids = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), 'id', 'event_uuid');
        self::assertSame(1, $this->queue->retry([(int)$ids['ol-old']]));
        $row = $this->objectManager->create(QueueRowLoader::class)->load('intelligence-' . $ids['cl-old']);
        self::assertIsArray($row);
        self::assertTrue($this->objectManager->create(Resend::class)->resend($row, 'admin'));

        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('ingest');
        $this->runCron($client);

        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertCount(5, $rows);
        $byUuid = array_column($rows, null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $byUuid['ol-old']['status']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED_ORDER, $byUuid['ol-old']['last_error']);
        $again = end($rows);
        self::assertSame('5', (string)$again['entity_id']);
        self::assertSame(IngestEvent::STATUS_SENT, $again['status']);
        self::assertSame(IngestQueue::SKIPPED_SUPERSEDED_CUSTOMER, $again['last_error']);
    }

    /**
     * @param Client&\PHPUnit\Framework\MockObject\MockObject $client
     */
    private function runCron(Client $client): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(true);
        $this->buildCron($settings, $client)->execute();
    }

    private function buildCron(Settings $settings, Client $client): FlushIngestQueue
    {
        /** @var Logger $logger */
        $logger = $this->objectManager->get(Logger::class);
        /** @var Json $serializer */
        $serializer = $this->objectManager->get(Json::class);

        return new FlushIngestQueue(
            $this->oneEngineConnection($settings, $client),
            $this->queue,
            $this->createMock(CatalogIngest::class),
            $this->createMock(OptOutReplay::class),
            $serializer,
            $logger,
            new IngestPace(),
            $this->objectManager->create(JobManager::class)
        );
    }

    /**
     * Catalog rows fi-g1..fi-g$count, item SKU-n each.
     */
    private function enqueueCatalog(int $count): void
    {
        for ($n = 1; $n <= $count; $n++) {
            $this->queue->enqueue(Client::DOMAIN_CATALOG, ['sku' => 'SKU-' . $n], 'SKU-' . $n, null, 'fi-g' . $n);
        }
    }

    /**
     * How many statements recorded a failed attempt while $run ran: the
     * UPDATEs of the queue table that set last_error and a failed outcome
     * (a claim sets no error, a delivery no pending or failed status).
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
            $outcomes = [IngestEvent::STATUS_PENDING, IngestEvent::STATUS_FAILED];
            $failed = str_contains($query, 'CASE') || array_intersect($outcomes, $profile->getQueryParams());
            if (str_starts_with($query, 'UPDATE `' . IngestEventResource::TABLE_NAME . '`')
                && str_contains($query, '`last_error`')
                && $failed
            ) {
                $writes++;
            }
        }
        $profiler->clear();

        return $writes;
    }
}
