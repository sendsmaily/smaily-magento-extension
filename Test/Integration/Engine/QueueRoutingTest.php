<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\VirtualPace;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-237: the engine ingest queue routed by Campaign Intelligence
 * connection, against a real smaily_ingest_queue table — a row's store_id
 * names its connection (NULL: the primary) — and the send run against a
 * mock engine that tells the engine stores apart by their API keys.
 *
 * Separate stores here: the primary serves website 1 (store views 1 and
 * 4), the connection of website 2 serves store views 2 and 3; store view 9
 * belongs to a website no connection serves.
 */
class QueueRoutingTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private IngestQueue $queue;

    /** @var list<array{key: string, path: string, ids: string[]}> the requests the engine got, in order */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(IngestQueue::class);
    }

    public function testAClaimReadsTheRowsOfItsConnectionOnly(): void
    {
        $this->seedRows();
        [$primary, $latvia] = $this->separateStores();

        self::assertSame(['null', 'one', 'four'], $this->uuids($this->queue->claimBatch('catalog', 100, $primary)));
        self::assertSame(['two', 'three'], $this->uuids($this->queue->claimBatch('catalog', 100, $latvia)));

        $pending = array_column(
            array_filter(
                $this->fetchAll(IngestEventResource::TABLE_NAME),
                static fn (array $row): bool => $row['status'] === IngestEvent::STATUS_PENDING
            ),
            'event_uuid'
        );
        self::assertSame(['nine'], array_values($pending), 'A row no connection serves waits');
    }

    /**
     * Under one store the primary serves every website: its claim is the
     * one it always was, a row of a store view deleted since it was queued
     * included.
     */
    public function testThePrimaryOfOneStoreClaimsEveryRow(): void
    {
        $this->seedRows();
        $primary = $this->engineConnection(0, $this->settings('sk_ee'), [1, 2], [1, 2, 3, 4], 1, true);

        self::assertSame(
            ['null', 'one', 'two', 'three', 'four', 'nine'],
            $this->uuids($this->queue->claimBatch('catalog', 100, $primary))
        );
    }

    /**
     * The catalog_changed markers belong to no connection: claimed without
     * one, all of them.
     */
    public function testTheChangedMarkersAreClaimedWithoutAConnection(): void
    {
        $this->queue->enqueueMany(CatalogIngest::DOMAIN_CHANGED, [
            ['payload' => [], 'entity_id' => '7', 'store_id' => null],
            ['payload' => [], 'entity_id' => '8', 'store_id' => null],
        ]);

        self::assertCount(2, $this->queue->claimBatch(CatalogIngest::DOMAIN_CHANGED, 100));
    }

    public function testTheUnsentPayloadsAndTheRowsReadAreTheConnections(): void
    {
        [$primary, $latvia] = $this->separateStores();
        $this->queue->enqueue('catalog', ['sku' => 'EE'], '5', 1, 'ee');
        $this->queue->enqueue('catalog', ['sku' => 'LV'], '5', 2, 'lv');
        $this->queue->enqueue('customers', ['email' => 'a@example.invalid'], '42', 2, 'c-lv');

        self::assertSame(['5' => '{"sku":"EE"}'], $this->queue->latestUndeliveredPayloads('catalog', ['5'], $primary));
        self::assertSame(['5' => '{"sku":"LV"}'], $this->queue->latestUndeliveredPayloads('catalog', ['5'], $latvia));
        self::assertSame(['5' => '{"sku":"LV"}'], $this->queue->latestUndeliveredPayloads('catalog', ['5']));
        self::assertTrue($this->queue->hasRow('customers', '42', $latvia));
        self::assertFalse($this->queue->hasRow('customers', '42', $primary));

        // The same row for the other connection is still queued for it.
        self::assertSame(1, $this->queue->enqueueChangedPayloads('catalog', ['5' => ['sku' => 'EE']], $latvia));
        self::assertSame(0, $this->queue->enqueueChangedPayloads('catalog', ['5' => ['sku' => 'EE']], $primary));
        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertSame('2', (string)end($rows)['store_id'], 'Queued with the connection\'s canonical store view');
    }

    /**
     * Two engine stores: each row reaches only its own connection's engine
     * store, with that connection's key, and both connections take turns
     * in one run.
     */
    public function testEachRowReachesOnlyTheEngineStoreOfItsConnection(): void
    {
        $this->seedRows();
        $this->queue->enqueue('orders', ['external_order_id' => 'EE-1'], 'EE-1', null, 'o-ee');
        $this->queue->enqueue('orders', ['external_order_id' => 'LV-1'], 'LV-1', 3, 'o-lv');
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '7'], '7', 4, 'r-ee');
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '8'], '8', 2, 'r-lv');
        [$primary, $latvia] = $this->separateStores();

        $this->flush($this->engineConnections([
            [$primary, $this->client($primary->settings())],
            [$latvia, $this->client($latvia->settings())],
        ]));

        self::assertSame([
            ['key' => 'sk_ee', 'path' => '/api/v1/ingest/catalog', 'ids' => ['null', 'one', 'four']],
            ['key' => 'sk_ee', 'path' => '/api/v1/ingest/orders', 'ids' => ['o-ee']],
            ['key' => 'sk_ee', 'path' => '/api/v1/ingest/catalog_remove', 'ids' => ['7']],
            ['key' => 'sk_lv', 'path' => '/api/v1/ingest/catalog', 'ids' => ['two', 'three']],
            ['key' => 'sk_lv', 'path' => '/api/v1/ingest/orders', 'ids' => ['o-lv']],
            ['key' => 'sk_lv', 'path' => '/api/v1/ingest/catalog_remove', 'ids' => ['8']],
        ], $this->requests);
        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), 'status', 'event_uuid');
        self::assertSame(IngestEvent::STATUS_PENDING, $rows['nine'], 'Sent to no engine store');
        unset($rows['nine']);
        self::assertSame([IngestEvent::STATUS_SENT], array_values(array_unique($rows)));
    }

    /**
     * The single-connection golden run: under one store every row — NULL,
     * the canonical store view, a deleted store view — goes to the one
     * engine store, in the batches and the order the run always made.
     */
    public function testOneConnectionSendsWhatItAlwaysSent(): void
    {
        for ($n = 1; $n <= 150; $n++) {
            $this->queue->enqueue('catalog', ['sku' => 'S' . $n], (string)$n, [null, 1, 99][$n % 3], 'cat-' . $n);
        }
        $this->queue->enqueue('customers', ['email' => 'c@example.invalid'], '1', 1, 'cust-1');
        for ($n = 1; $n <= 60; $n++) {
            $this->queue->enqueue('orders', ['external_order_id' => (string)$n], (string)$n, null, 'ord-' . $n);
        }
        $this->queue->enqueue(Client::DOMAIN_CATALOG_REMOVE, ['product_id' => '7'], '7', 1, 'rem-7');
        $settings = $this->settings('sk_one');

        $this->flush($this->oneEngineConnection($settings, $this->client($settings)));

        $range = static fn (string $prefix, int $from, int $to): array => array_map(
            static fn (int $n): string => $prefix . $n,
            range($from, $to)
        );
        self::assertSame([
            ['key' => 'sk_one', 'path' => '/api/v1/ingest/catalog', 'ids' => $range('cat-', 1, 100)],
            ['key' => 'sk_one', 'path' => '/api/v1/ingest/customers', 'ids' => ['cust-1']],
            ['key' => 'sk_one', 'path' => '/api/v1/ingest/orders', 'ids' => $range('ord-', 1, 50)],
            ['key' => 'sk_one', 'path' => '/api/v1/ingest/catalog_remove', 'ids' => ['7']],
            ['key' => 'sk_one', 'path' => '/api/v1/ingest/catalog', 'ids' => $range('cat-', 101, 150)],
            ['key' => 'sk_one', 'path' => '/api/v1/ingest/orders', 'ids' => $range('ord-', 51, 60)],
        ], $this->requests);
        self::assertSame(0, $this->queue->countPending('catalog') + $this->queue->countPending('orders'));
    }

    /**
     * Catalog rows of store views NULL, 1, 2, 3, 4 and 9, oldest first.
     */
    private function seedRows(): void
    {
        foreach (['null' => null, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'nine' => 9] as $uuid => $store) {
            $this->queue->enqueue('catalog', ['sku' => strtoupper($uuid)], $uuid, $store, $uuid);
        }
    }

    /**
     * @return array{0: Connection, 1: Connection} the primary and the connection of website 2
     */
    private function separateStores(): array
    {
        return [
            $this->engineConnection(0, $this->settings('sk_ee'), [1], [1, 4], 1),
            $this->engineConnection(2, $this->settings('sk_lv'), [2], [2, 3], 2),
        ];
    }

    private function settings(string $apiKey): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(true);
        $settings->method('isRefused')->willReturn(false);
        $settings->method('getApiKey')->willReturn($apiKey);
        $settings->method('getEndpoint')->willReturnCallback(
            static fn (string $key): string => 'https://engine.test/api/v1/ingest/' . substr($key, strlen('ingest_'))
        );

        return $settings;
    }

    /**
     * The real engine client of a connection, whose HTTP goes to the mock engine.
     */
    private function client(Settings $settings): Client
    {
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            fn (array $config = []): HttpClient => new HttpClient(['handler' => $this->mockEngine()] + $config)
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);

        return new Client(
            $settings,
            $httpClientFactory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $this->createMock(Logger::class),
            $this->createMock(SleeperInterface::class),
            new StorefrontScript($storeManager, $this->createMock(Http::class))
        );
    }

    /**
     * The engine: a D6 reply for every item, a §3b reply for a removal;
     * each request recorded with the key it came with and the items' ids.
     */
    private function mockEngine(): callable
    {
        return function (RequestInterface $request) {
            $body = json_decode((string)$request->getBody(), true);
            $path = $request->getUri()->getPath();
            if (isset($body['product_ids'])) {
                $ids = array_map('strval', $body['product_ids']);
                $reply = ['ok' => true, 'removed_products' => count($ids), 'rows_tombstoned' => 0, 'not_found' => []];
            } else {
                $items = (array)reset($body);
                $ids = array_map(static fn (array $item): string => (string)$item['event_id'], $items);
                $reply = ['ok' => true, 'processed' => count($items), 'deduplicated' => 0, 'errors' => []];
            }
            $this->requests[] = [
                'key' => substr($request->getHeaderLine('Authorization'), strlen('Bearer ')),
                'path' => $path,
                'ids' => $ids,
            ];

            return Create::promiseFor(new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string)json_encode($reply)
            ));
        };
    }

    private function flush(Connections $connections): void
    {
        (new FlushIngestQueue(
            $connections,
            $this->queue,
            $this->createMock(CatalogIngest::class),
            $this->createMock(OptOutReplay::class),
            new Json(),
            $this->createMock(Logger::class),
            new VirtualPace(),
            $this->objectManager->create(JobManager::class)
        ))->execute();
    }

    /**
     * @param IngestEvent[] $events
     * @return string[]
     */
    private function uuids(array $events): array
    {
        return array_map(static fn (IngestEvent $event): string => $event->getEventUuid(), $events);
    }
}
