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
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Cron\FlushIngestQueue;
use Smaily\Connect\Model\Backfill\EngineCatalogProcessor;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\EngineCustomersProcessor;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\OptOutReplay;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\FrozenImportClock;
use Smaily\Connect\Test\Integration\Support\SalesOrderHarness;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Test\Integration\Support\VirtualPace;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-237: the history imports of two Campaign Intelligence connections
 * — each connection's catalog, customers and orders imports, run by the
 * import processors and sent by the send run — against a real ingest
 * queue, job table, flag table and sales_order table, and a mock engine
 * that tells the engine stores apart by their API keys. Every row reaches
 * only the engine store of its connection.
 *
 * Separate stores: the primary serves website 1 (store view 1), the
 * connection of website 2 serves website 2 (store view 2). Products 1 and
 * 2, customers 10 and 11 and orders 1 and 2 are the primary's; product 3,
 * customer 20 and orders 3 and 4 the other connection's.
 */
class ImportRoutingTest extends IntegrationTestCase
{
    use EngineConnectionsStub;
    use SalesOrderHarness;

    /** @var array<int, int[]> each connection's products and customers, by key */
    private const PRODUCTS = [0 => [1, 2], 2 => [3]];
    private const CUSTOMERS = [0 => [10, 11], 2 => [20]];

    /** @var list<array{key: string, path: string, ids: string[]}> the requests the engine got, in order */
    private array $requests = [];

    private JobManager $jobManager;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = new SchemaInstaller($this->connection);
        $schema->createSalesOrder();
        $schema->createFlag();
        $this->jobManager = $this->objectManager->create(JobManager::class);
        foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 2] as $orderId => $storeId) {
            $this->connection->insert('sales_order', [
                'entity_id' => $orderId,
                'increment_id' => 'O' . $orderId,
                'store_id' => $storeId,
                'state' => Order::STATE_COMPLETE,
                'customer_email' => 'o' . $orderId . '@example.invalid',
                'grand_total' => 10,
                'order_currency_code' => 'EUR',
                'created_at' => $this->clockDate(-30 * 86400 - $orderId),
            ]);
        }
    }

    protected function tearDown(): void
    {
        (new SchemaInstaller($this->connection))->dropSalesOrder();
        parent::tearDown();
    }

    public function testEachConnectionsImportsReachOnlyItsEngineStore(): void
    {
        $primary = $this->engineConnection(0, $this->settings('sk_ee'), [1], [1], 1);
        $second = $this->engineConnection(2, $this->settings('sk_lv'), [2], [2], 2);
        $connections = $this->engineConnections([
            [$primary, $this->client($primary->settings())],
            [$second, $this->client($second->settings())],
        ]);
        $processors = $this->processors($connections);
        foreach ([0, 2] as $key) {
            foreach ([Job::TYPE_CATALOG, Job::TYPE_CUSTOMERS, Job::TYPE_ORDERS] as $type) {
                $job = $this->jobManager->start($type, Job::TARGET_ENGINE, $key);
                for ($run = 0; $run < 5 && $job->getStatus() !== Job::STATUS_COMPLETED; $run++) {
                    $processors[$type]->process($job);
                }
                self::assertSame(Job::STATUS_COMPLETED, $job->getStatus(), $type . ' of ' . $key);
            }
        }

        $this->flush($connections);

        self::assertSame([
            ['key' => 'sk_ee', 'path' => '/api/v1/ingest/catalog', 'ids' => ['P1', 'P2']],
            ['key' => 'sk_ee', 'path' => '/api/v1/ingest/customers', 'ids' => ['10', '11']],
            ['key' => 'sk_ee', 'path' => '/api/v1/ingest/orders', 'ids' => ['O1', 'O2']],
            ['key' => 'sk_lv', 'path' => '/api/v1/ingest/catalog', 'ids' => ['P3']],
            ['key' => 'sk_lv', 'path' => '/api/v1/ingest/customers', 'ids' => ['20']],
            ['key' => 'sk_lv', 'path' => '/api/v1/ingest/orders', 'ids' => ['O3', 'O4']],
        ], $this->requests);
        self::assertSame(
            ['O1' => '1', 'O2' => '1', 'O3' => '2', 'O4' => '2'],
            $this->connection->fetchPairs(
                $this->connection->select()->from('smaily_ingest_queue', ['entity_id', 'store_id'])
                    ->where('domain = ?', Client::DOMAIN_ORDERS)
                    ->order('entity_id ASC')
            ),
            'Each row carries its connection\'s canonical store view'
        );
        self::assertNotSame('', $this->flag(HistoryImport::FLAG));
        self::assertNotSame('', $this->flag(HistoryImport::FLAG . '_c2'), 'Each connection has its own history import');
    }

    /**
     * The catalog, customers and orders processors over these connections.
     *
     * @return array<string, ProcessorInterface>
     */
    private function processors(Connections $connections): array
    {
        $queue = $this->objectManager->create(IngestQueue::class);
        $logger = $this->createMock(Logger::class);
        $jobConnection = $this->engineJobConnection($connections, $this->jobManager);
        $historyConfig = $this->createMock(HistoryConfig::class);
        $historyConfig->method('forNewImport')->willReturn(HistoryConfig::FALLBACK);
        $historyConfig->method('stored')->willReturn(HistoryConfig::FALLBACK);
        $historyImport = $this->objectManager->create(HistoryImport::class, [
            'historyConfig' => $historyConfig,
            'dateTime' => $this->clock,
            'connections' => $connections,
        ]);

        $loader = $this->createMock(CatalogProductLoader::class);
        $loader->method('count')->willReturnCallback(
            static fn (Connection $connection): int => count(self::PRODUCTS[$connection->key()])
        );
        $loader->method('loadPage')->willReturnCallback(
            fn (int $afterId, int $pageSize, Connection $connection): array => array_map(
                fn (int $id): Product => $this->product($id),
                array_values(array_filter(self::PRODUCTS[$connection->key()], static fn (int $id): bool => $id > $afterId))
            )
        );
        $catalogBuilder = $this->createMock(CatalogPayloadBuilder::class);
        $catalogBuilder->method('isIngestible')->willReturn(true);
        $catalogBuilder->method('build')->willReturnCallback(
            static fn (Product $product): array => ['sku' => 'P' . $product->getId()]
        );
        $catalog = new EngineCatalogProcessor(
            $this->jobManager,
            $queue,
            new CatalogIngest(
                $loader,
                $this->createMock(ProductResource::class),
                $catalogBuilder,
                $queue,
                $logger,
                $connections
            ),
            $loader,
            new FrozenImportClock(),
            $jobConnection
        );

        $scope = $this->createMock(EngineCustomerScope::class);
        $scope->method('count')->willReturnCallback(
            static fn (string $horizon, Connection $connection): int => count(self::CUSTOMERS[$connection->key()])
        );
        $scope->method('page')->willReturnCallback(
            static fn (string $horizon, int $afterId, int $limit, Connection $connection): array => array_values(
                array_filter(self::CUSTOMERS[$connection->key()], static fn (int $id): bool => $id > $afterId)
            )
        );
        $customerBuilder = $this->createMock(CustomerPayloadBuilder::class);
        $customerBuilder->method('build')->willReturnCallback(
            static fn (CustomerInterface $customer): array => ['external_id' => (string)$customer->getId()]
        );
        $customers = new EngineCustomersProcessor(
            $this->jobManager,
            $this->customerRepository(),
            $this->objectManager->create(SearchCriteriaBuilder::class),
            $customerBuilder,
            $queue,
            new SkippedRow($logger),
            $scope,
            $historyImport,
            new FrozenImportClock(),
            $jobConnection
        );

        $orders = new EngineOrdersProcessor(
            $this->jobManager,
            $this->orderCollectionFactory(false),
            $this->wireOrderBuilder(),
            $queue,
            new SkippedRow($logger),
            $historyImport,
            new FrozenImportClock(),
            $jobConnection
        );

        return [Job::TYPE_CATALOG => $catalog, Job::TYPE_CUSTOMERS => $customers, Job::TYPE_ORDERS => $orders];
    }

    private function product(int $id): Product
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }

    private function customerRepository(): CustomerRepositoryInterface
    {
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(
            function (SearchCriteriaInterface $criteria): CustomerSearchResultsInterface {
                $customers = [];
                foreach ((array)$criteria->getFilterGroups()[0]->getFilters()[0]->getValue() as $id) {
                    $customer = $this->createMock(CustomerInterface::class);
                    $customer->method('getId')->willReturn((int)$id);
                    $customer->method('getEmail')->willReturn('c' . $id . '@example.invalid');
                    $customers[] = $customer;
                }
                $results = $this->createMock(CustomerSearchResultsInterface::class);
                $results->method('getItems')->willReturn($customers);

                return $results;
            }
        );

        return $repository;
    }

    /**
     * The order's wire row from its sales_order row alone.
     */
    private function wireOrderBuilder(): OrderPayloadBuilder
    {
        return new class extends OrderPayloadBuilder {
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedFunction
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

    private function settings(string $apiKey): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
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
     * The engine: a D6 reply for every item; each request recorded with the
     * key it came with and its items' keys.
     */
    private function mockEngine(): callable
    {
        return function (RequestInterface $request) {
            $body = (array)json_decode((string)$request->getBody(), true);
            $items = (array)reset($body);
            $this->requests[] = [
                'key' => substr($request->getHeaderLine('Authorization'), strlen('Bearer ')),
                'path' => $request->getUri()->getPath(),
                'ids' => array_map(
                    static fn (array $item): string => (string)($item['sku'] ?? $item['external_order_id']
                        ?? $item['external_id']),
                    $items
                ),
            ];

            return Create::promiseFor(new Response(
                200,
                ['Content-Type' => 'application/json'],
                (string)json_encode(['ok' => true, 'processed' => count($items), 'deduplicated' => 0, 'errors' => []])
            ));
        };
    }

    private function flush(Connections $connections): void
    {
        (new FlushIngestQueue(
            $connections,
            $this->objectManager->create(IngestQueue::class),
            $this->createMock(CatalogIngest::class),
            $this->createMock(OptOutReplay::class),
            new Json(),
            $this->createMock(Logger::class),
            new VirtualPace(),
            $this->jobManager
        ))->execute();
    }

    private function flag(string $code): string
    {
        $data = $this->connection->fetchOne(
            $this->connection->select()->from('flag', ['flag_data'])->where('flag_code = ?', $code)
        );

        return $data === false ? '' : (string)$data;
    }
}
