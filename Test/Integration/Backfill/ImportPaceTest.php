<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item as OrderItemResource;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Magento\SalesSequence\Model\Manager as SequenceManager;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-232: one cron minute — the import tick, then the send run — moves a
 * whole import backlog (IngestPace::IMPORT_BACKLOG_ROWS orders) to the
 * engine, at the pace, inside the send budget; an order the store places
 * meanwhile goes out in the same run, not after the history.
 *
 * Real here: the sales_order and sales_order_item tables, the orders import
 * and its payload builder, the job table, the ingest queue, the send run,
 * the engine client with its JSON and D6 handling, and the pace with its
 * real clock and waits. The engine is a mock that answers each request with
 * a D6 reply at once and records when it came.
 */
class ImportPaceTest extends IntegrationTestCase
{
    /** Two pages more than the backlog: the second minute finishes the import. */
    private const ORDERS = IngestPace::IMPORT_BACKLOG_ROWS + 200;

    private JobManager $jobManager;

    /** @var list<array{at: float, path: string, items: int}> the requests the engine got */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/OrderItemCollectionFactory.php';
        $schema = new SchemaInstaller($this->connection);
        $schema->createSalesOrder();
        $schema->createSalesOrderItem();
        $this->jobManager = $this->objectManager->create(JobManager::class);
    }

    protected function tearDown(): void
    {
        $schema = new SchemaInstaller($this->connection);
        $schema->dropSalesOrderItem();
        $schema->dropSalesOrder();
        parent::tearDown();
    }

    public function testOneCronMinuteSendsAWholeImportBacklogAtThePace(): void
    {
        $this->seedOrders(self::ORDERS);
        $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $tick = $this->tick();
        $send = $this->sendRun();

        // Minute 1: the tick queues up to the backlog …
        $tick->execute();
        self::assertSame(IngestPace::IMPORT_BACKLOG_ROWS, $this->pending(), 'The tick queues the whole backlog');
        // … a shopper places an order while the import runs …
        $this->objectManager->get(IngestQueue::class)
            ->enqueue(Client::DOMAIN_ORDERS, ['external_order_id' => 'live-1'], 'live-1', null, 'live-order');
        // … and the send run sends all of it.
        $started = microtime(true);
        $send->execute();
        $sendSeconds = microtime(true) - $started;

        self::assertSame(0, $this->pending(), 'One send run empties the queue');
        $batches = (int)ceil((IngestPace::IMPORT_BACKLOG_ROWS + 1) / Client::DOMAIN_BATCH_LIMITS['orders']);
        self::assertCount($batches, $this->requests);
        self::assertSame(IngestEvent::STATUS_SENT, $this->statusOf('live-order'), 'The live order went in the same run');
        self::assertLessThan(IngestPace::SEND_BUDGET_SECONDS, $sendSeconds);
        self::assertGreaterThanOrEqual(
            ($batches - 1) / IngestPace::REQUESTS_PER_SECOND - 0.05,
            $this->requests[$batches - 1]['at'] - $this->requests[0]['at'],
            'The requests keep to the pace'
        );

        // Minute 2: the rest, and the import completes.
        $tick->execute();
        $send->execute();

        $job = $this->connection->fetchRow($this->connection->select()->from(JobResource::TABLE_NAME));
        self::assertSame(Job::STATUS_COMPLETED, $job['status']);
        self::assertSame((string)self::ORDERS, (string)$job['processed_count']);
        self::assertSame(0, $this->pending());
        self::assertSame(self::ORDERS + 1, array_sum(array_column($this->requests, 'items')));
    }

    private function tick(): BackfillTick
    {
        $logger = $this->createMock(Logger::class);
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isRefused')->willReturn(false);
        $orders = new EngineOrdersProcessor(
            $this->jobManager,
            $this->orderCollectionFactory(),
            new OrderPayloadBuilder(
                $this->objectManager->get(ResourceConnection::class),
                $this->orderItemCollectionFactory(),
                $logger
            ),
            $this->objectManager->create(IngestQueue::class),
            new SkippedRow($logger)
        );

        return new BackfillTick($this->jobManager, $engineSettings, $logger, [
            Job::TYPE_ORDERS . ':' . Job::TARGET_ENGINE => $orders,
        ]);
    }

    /**
     * The send run with the real engine client, whose HTTP goes to the mock
     * engine, and the real pace.
     */
    private function sendRun(): FlushIngestQueue
    {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isSendingAllowed')->willReturn(true);
        $settings->method('isRefused')->willReturn(false);
        $settings->method('getApiKey')->willReturn('sk_test_pace');
        $settings->method('getEndpoint')->willReturnCallback(
            static fn (string $key): string => 'https://engine.test/api/v1/ingest/' . substr($key, strlen('ingest_'))
        );

        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            fn (array $config = []): HttpClient => new HttpClient(['handler' => $this->mockEngine()] + $config)
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $client = new Client(
            $settings,
            $httpClientFactory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $this->createMock(Logger::class),
            $this->createMock(SleeperInterface::class),
            new StorefrontScript($storeManager, $this->createMock(Http::class))
        );

        return new FlushIngestQueue(
            $settings,
            $this->objectManager->create(IngestQueue::class),
            $this->createMock(CatalogIngest::class),
            $client,
            $this->createMock(OptOutReplay::class),
            new Json(),
            $this->createMock(Logger::class),
            new IngestPace()
        );
    }

    /**
     * The engine: a D6 reply for every item at once; each request recorded.
     */
    private function mockEngine(): callable
    {
        return function (RequestInterface $request) {
            $body = json_decode((string)$request->getBody(), true);
            $items = count((array)reset($body));
            $this->requests[] = [
                'at' => microtime(true),
                'path' => $request->getUri()->getPath(),
                'items' => $items,
            ];

            return Create::promiseFor(new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string)json_encode(['ok' => true, 'processed' => $items, 'deduplicated' => 0, 'errors' => []])
            ));
        };
    }

    private function pending(): int
    {
        return $this->objectManager->create(IngestQueue::class)->countPending(Client::DOMAIN_ORDERS);
    }

    private function statusOf(string $eventUuid): string
    {
        return (string)$this->connection->fetchOne(
            $this->connection->select()->from(IngestEventResource::TABLE_NAME, ['status'])
                ->where('event_uuid = ?', $eventUuid)
        );
    }

    /**
     * Orders 1..$count, one line each.
     */
    private function seedOrders(int $count): void
    {
        $orders = [];
        $lines = [];
        for ($id = 1; $id <= $count; $id++) {
            $orders[] = [
                'entity_id' => $id,
                'increment_id' => (string)(100000000 + $id),
                'state' => Order::STATE_COMPLETE,
                'customer_email' => 'o' . $id . '@example.invalid',
                'grand_total' => 10,
                'order_currency_code' => 'EUR',
                'created_at' => '2024-01-01 10:00:00',
            ];
            $lines[] = [
                'order_id' => $id,
                'sku' => 'SKU-' . $id,
                'product_type' => 'simple',
                'qty_ordered' => 1,
                'row_total' => 10,
                'row_total_incl_tax' => 10,
                'product_options' => '{"info_buyRequest":{"qty":1}}',
            ];
        }
        foreach (array_chunk($orders, 500) as $chunk) {
            $this->connection->insertMultiple('sales_order', $chunk);
        }
        foreach (array_chunk($lines, 500) as $chunk) {
            $this->connection->insertMultiple('sales_order_item', $chunk);
        }
    }

    /**
     * The orders, read from sales_order the way the import pages through
     * them (as UnreadableImportRowsTest reads them).
     */
    private function orderCollectionFactory(): OrderCollectionFactory
    {
        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturnCallback(function (): OrderCollection {
            $after = 0;
            $limit = 0;
            $collection = $this->createMock(OrderCollection::class);
            $collection->method('getSize')->willReturnCallback(
                fn (): int => (int)$this->connection->fetchOne('SELECT COUNT(*) FROM sales_order')
            );
            $collection->method('addFieldToFilter')->willReturnCallback(
                function (string $field, array $condition) use (&$after, $collection): OrderCollection {
                    $after = (int)$condition['gt'];

                    return $collection;
                }
            );
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('setPageSize')->willReturnCallback(
                function (int $size) use (&$limit, $collection): OrderCollection {
                    $limit = $size;

                    return $collection;
                }
            );
            $collection->method('getItems')->willReturnCallback(function () use (&$after, &$limit): array {
                $rows = $this->connection->fetchAll(
                    $this->connection->select()->from('sales_order')
                        ->where('entity_id > ?', $after)->order('entity_id ASC')->limit($limit)
                );

                return array_map(fn (array $row): Order => $this->order($row), $rows);
            });

            return $collection;
        });

        return $factory;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function order(array $row): Order
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItems'])
            ->getMock();
        $order->setData($row);
        $order->method('getItems')->willReturnCallback(
            fn (): array => $this->orderItemCollectionFactory()->create()
                ->setOrderFilter((int)$row['entity_id'])
                ->getItems()
        );

        return $order;
    }

    private function orderItemCollectionFactory(): OrderItemCollectionFactory
    {
        $resource = $this->objectManager->create(
            OrderItemResource::class,
            ['sequenceManager' => $this->createMock(SequenceManager::class)]
        );
        $entityFactory = $this->createMock(EntityFactoryInterface::class);
        $entityFactory->method('create')->willReturnCallback(
            static function (string $class) use ($resource): object {
                $item = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
                (new \ReflectionProperty(AbstractModel::class, '_resource'))->setValue($item, $resource);

                return $item;
            }
        );

        $factory = $this->createMock(OrderItemCollectionFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): OrderItemCollection => $this->objectManager->create(
                OrderItemCollection::class,
                ['resource' => $resource, 'entityFactory' => $entityFactory]
            )
        );

        return $factory;
    }
}
