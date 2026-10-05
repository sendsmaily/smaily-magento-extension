<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The engine ingest flush cron against a real queue table with the HTTP
 * client mocked: D6 per-item error mapping, transport vs request failures.
 */
class FlushIngestQueueTest extends IntegrationTestCase
{
    private IngestQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(IngestQueue::class);
    }

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
                'errors' => [['index' => 1, 'field' => 'price', 'message' => 'must be a number']],
            ]);
        $this->runCron($client);

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fi-1']['status']);
        self::assertSame(IngestEvent::STATUS_SENT, $rows['fi-3']['status']);
        self::assertSame(IngestEvent::STATUS_FAILED, $rows['fi-2']['status'], 'Per-item errors are terminal');
        self::assertSame('price: must be a number', $rows['fi-2']['last_error']);
        self::assertNull($rows['fi-2']['next_retry_at']);
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
            $settings,
            $this->queue,
            $this->createMock(CatalogIngest::class),
            $client,
            $this->createMock(OptOutReplay::class),
            $serializer,
            $logger
        );
    }
}
