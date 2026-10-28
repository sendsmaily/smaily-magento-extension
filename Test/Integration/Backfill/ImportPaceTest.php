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
use Magento\Sales\Api\Data\OrderInterface;
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
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\HistoryConfig;
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
 * CON-241: a pilot-store-sized history goes newest first, its start part
 * before anything older, nothing past the horizon, and the engine hears
 * each phase of it (contract v1.20.0 §25).
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

    /** @var list<string> the ordered_at of each order the engine got, in arrival order */
    private array $orderTimes = [];

    /** @var list<array{minute: int, phase: string, orders_sent: int}> the progress calls the engine got */
    private array $progress = [];

    private int $minute = 0;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/OrderItemCollectionFactory.php';
        $schema = new SchemaInstaller($this->connection);
        $schema->createSalesOrder();
        $schema->createSalesOrderItem();
        $schema->createFlag();
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

    /**
     * CON-241: a history the size of the pilot store's — 80,000 orders over
     * thirteen years, 12,000 of them in the last 18 months and 40,000 inside
     * 60 months — goes newest first: every order of the start part reaches
     * the engine before any older one, no order older than 60 months is
     * sent, and the engine hears `start_part_complete` once the start part
     * is in, long before `complete`. One loop is one cron minute: the tick,
     * the send run at its pace (on a clock that does not wait), and a minute
     * passing.
     */
    public function testAPilotSizedHistorySendsItsStartPartFirst(): void
    {
        $month = 30.44 * 86400;
        $now = $this->clock->now();
        $this->seedOrders(80000, static function (int $id) use ($now, $month): string {
            // 1..12,000 in the start part, 12,001..40,000 older inside the
            // horizon, the rest older than 60 months (up to 13 years).
            $age = match (true) {
                $id <= 12000 => 3600 + ($id - 1) * (17.9 * $month - 3600) / 12000,
                $id <= 40000 => 18.1 * $month + ($id - 12001) * 41.8 * $month / 28000,
                default => 60.1 * $month + ($id - 40001) * 95.9 * $month / 40000,
            };

            return gmdate('Y-m-d H:i:s', (int)($now - $age));
        }, false);
        $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $client = $this->client();
        $tick = $this->tick($client, $this->wireOrderBuilder());
        $send = $this->sendRun($client, $this->paceWithoutWaiting());

        $startPartMinute = 0;
        $completeMinute = 0;
        for ($this->minute = 1; $this->minute <= 60 && !$completeMinute; $this->minute++) {
            $tick->execute();
            $send->execute();
            $this->clock->travel(60);
            $phases = array_column($this->progress, 'phase', 'minute');
            $startPartMinute = $startPartMinute ?: (int)array_search('start_part_complete', $phases, true);
            $completeMinute = (int)array_search('complete', $phases, true);
        }

        $horizon = gmdate('Y-m-d\TH:i:s\Z', (int)($now - 60 * $month));
        $startFrom = gmdate('Y-m-d\TH:i:s\Z', (int)($now - 18 * $month));
        self::assertCount(40000, $this->orderTimes, 'Every order inside 60 months, and no other');
        self::assertGreaterThan($horizon, min($this->orderTimes), 'No order older than the horizon');
        $startPart = array_keys(array_filter($this->orderTimes, static fn (string $at): bool => $at >= $startFrom));
        self::assertCount(12000, $startPart);
        self::assertSame(11999, max($startPart), 'The start part arrives before any older order');
        $sorted = $this->orderTimes;
        rsort($sorted);
        self::assertSame($sorted, $this->orderTimes, 'Newest first, throughout');

        self::assertSame(
            ['started', 'start_part_complete', 'complete'],
            array_values(array_unique(array_column($this->progress, 'phase')))
        );
        self::assertSame(8, $startPartMinute, 'The start part is in after 8 minutes');
        self::assertSame(22, $completeMinute, 'The whole horizon after 22');
        self::assertSame(
            14000,
            $this->progress[array_search(8, array_column($this->progress, 'minute'), true)]['orders_sent'],
            'start_part_complete counts the orders the engine accepted: the start part\'s 12,000,'
            . ' and the 2,000 older ones that went in the minute the start part was found complete'
        );
    }

    private function tick(?Client $client = null, ?OrderPayloadBuilder $builder = null): BackfillTick
    {
        $logger = $this->createMock(Logger::class);
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isRefused')->willReturn(false);
        $engineSettings->method('isSendingAllowed')->willReturn(true);
        $historyConfig = $this->createMock(HistoryConfig::class);
        $historyConfig->method('forNewImport')->willReturn(HistoryConfig::FALLBACK);
        $historyImport = $this->objectManager->create(
            HistoryImport::class,
            ['historyConfig' => $historyConfig, 'dateTime' => $this->clock]
        );
        $queue = $this->objectManager->create(IngestQueue::class);
        $orders = new EngineOrdersProcessor(
            $this->jobManager,
            $this->orderCollectionFactory($builder === null),
            $builder ?? new OrderPayloadBuilder(
                $this->objectManager->get(ResourceConnection::class),
                $this->orderItemCollectionFactory(),
                $logger
            ),
            $queue,
            new SkippedRow($logger),
            $historyImport
        );
        $progress = $client === null
            ? $this->createMock(HistoryProgress::class)
            : new HistoryProgress(
                $historyImport,
                $this->jobManager,
                $queue,
                $client,
                $engineSettings,
                $this->clock,
                $logger
            );

        return new BackfillTick($this->jobManager, $engineSettings, $logger, $historyImport, $progress, [
            Job::TYPE_ORDERS . ':' . Job::TARGET_ENGINE => $orders,
        ]);
    }

    /**
     * The order's wire row from its sales_order row alone, without its
     * lines: the pilot-sized history measures the order the rows go in. Not
     * a mock, which would keep each of its 40,000 calls.
     */
    private function wireOrderBuilder(): OrderPayloadBuilder
    {
        return new class extends OrderPayloadBuilder {
            public function __construct()
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function build(OrderInterface $order): array
            {
                return [
                    'external_order_id' => (string)$order->getIncrementId(),
                    'customer_email' => (string)$order->getCustomerEmail(),
                    'ordered_at' => str_replace(' ', 'T', (string)$order->getCreatedAt()) . 'Z',
                    'total_amount' => 10,
                    'status' => 'completed',
                    'items' => [],
                ];
            }
        };
    }

    /**
     * The pace on a clock that moves only by its own waits: a send run
     * makes as many requests as at the real pace, at once.
     */
    private function paceWithoutWaiting(): IngestPace
    {
        return new class extends IngestPace {
            private float $virtualNow = 0.0;

            protected function now(): float
            {
                return $this->virtualNow;
            }

            protected function pause(float $seconds): void
            {
                $this->virtualNow += $seconds;
            }
        };
    }

    /**
     * The send run with the real engine client, whose HTTP goes to the mock
     * engine, and the real pace unless another is given.
     */
    private function sendRun(?Client $client = null, ?IngestPace $pace = null): FlushIngestQueue
    {
        $client ??= $this->client();

        return new FlushIngestQueue(
            $this->engineSettings(),
            $this->objectManager->create(IngestQueue::class),
            $this->createMock(CatalogIngest::class),
            $client,
            $this->createMock(OptOutReplay::class),
            new Json(),
            $this->createMock(Logger::class),
            $pace ?? new IngestPace(),
            $this->jobManager
        );
    }

    private function engineSettings(): EngineSettings
    {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isSendingAllowed')->willReturn(true);
        $settings->method('isRefused')->willReturn(false);
        $settings->method('getApiKey')->willReturn('sk_test_pace');
        $settings->method('getEndpoint')->willReturnCallback(
            static fn (string $key): string => 'https://engine.test/api/v1/ingest/' . substr($key, strlen('ingest_'))
        );

        return $settings;
    }

    /**
     * The real engine client, whose HTTP goes to the mock engine.
     */
    private function client(): Client
    {
        $settings = $this->engineSettings();
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
     * The engine: a D6 reply for every item at once, and `ok` to a progress
     * call; each request recorded.
     */
    private function mockEngine(): callable
    {
        return function (RequestInterface $request) {
            $body = json_decode((string)$request->getBody(), true);
            if (str_contains($request->getUri()->getPath(), 'history')) {
                $this->progress[] = [
                    'minute' => $this->minute,
                    'phase' => $body['phase'],
                    'orders_sent' => $body['entities']['orders']['sent'],
                ];

                return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{"ok":true}'));
            }
            foreach ($body['orders'] ?? [] as $order) {
                $this->orderTimes[] = (string)($order['ordered_at'] ?? '');
            }
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
     * Orders 1..$count, one line each unless $withLines is false; placed a
     * month ago unless $createdAt says when, by order id.
     *
     * @param (callable(int): string)|null $createdAt
     */
    private function seedOrders(int $count, ?callable $createdAt = null, bool $withLines = true): void
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
                'created_at' => $createdAt !== null ? $createdAt($id) : $this->clockDate(-30 * 86400),
            ];
            if ($withLines) {
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
            if (count($orders) === 500 || $id === $count) {
                $this->connection->insertMultiple('sales_order', $orders);
                $orders = [];
            }
            if ($lines && (count($lines) === 500 || $id === $count)) {
                $this->connection->insertMultiple('sales_order_item', $lines);
                $lines = [];
            }
        }
    }

    /**
     * The orders, read from sales_order the way the import pages through
     * them (as UnreadableImportRowsTest reads them).
     */
    private function orderCollectionFactory(bool $withLines = true): OrderCollectionFactory
    {
        $factory = $this->createMock(OrderCollectionFactory::class);
        $factory->method('create')->willReturnCallback(function () use ($withLines): OrderCollection {
            $ids = [];
            $collection = $this->createMock(OrderCollection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function (string $field, array $condition) use (&$ids, $collection): OrderCollection {
                    $ids = array_map('intval', $condition['in']);

                    return $collection;
                }
            );
            $collection->method('getItems')->willReturnCallback(function () use (&$ids, $withLines): array {
                $rows = $this->connection->fetchAll(
                    $this->connection->select()->from('sales_order')->where('entity_id IN (?)', $ids ?: [0])
                );

                return array_map(
                    fn (array $row): Order => $withLines ? $this->order($row) : $this->plainOrder($row),
                    $rows
                );
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

    /**
     * An order of its sales_order row alone, not a mock (see wireOrderBuilder()).
     *
     * @param array<string, mixed> $row
     */
    private function plainOrder(array $row): Order
    {
        $order = (new \ReflectionClass(Order::class))->newInstanceWithoutConstructor();
        $order->setData($row);

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
