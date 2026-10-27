<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Item as OrderItemResource;
use Magento\Sales\Model\ResourceModel\Order\Item\Collection as OrderItemCollection;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory as OrderItemCollectionFactory;
use Magento\SalesSequence\Model\Manager as SequenceManager;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\EngineCustomersProcessor;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-227: on the pilot store the customers import stopped at a customer
 * whose store view no longer exists ("The store that was requested wasn't
 * found"), and the orders import at an order line whose stored
 * product_options Magento could not unserialize ("Control character
 * error"). Run again now goes past both and completes: the customer goes
 * without a language, the order without its item options.
 *
 * Real here: the sales_order and sales_order_item tables, Magento's own
 * order-line collection and its JSON unserialize, the job table, the ingest
 * queue and the cron tick. Stand-ins: the customer repository (Magento's
 * EAV customer stack does not run in this harness) and the store lookup,
 * which throws exactly as Magento's StoreRepository does for a missing store.
 */
class UnreadableImportRowsTest extends IntegrationTestCase
{
    private const MISSING_STORE_ID = 9;

    /** A JSON string with a raw tab inside: Magento's Json serializer refuses it. */
    private const UNREADABLE_OPTIONS = "{\"info_buyRequest\":{\"note\":\"a\tb\"}}";

    private JobManager $jobManager;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

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

    public function testRunAgainGoesPastTheUnreadableRowsAndCompletes(): void
    {
        $this->seedOrder(1, '2013-05-02 10:00:00', '{"info_buyRequest":{"qty":1}}');
        $this->seedOrder(2, '2022-04-06 10:00:00', self::UNREADABLE_OPTIONS);
        $this->seedOrder(3, '2022-04-07 10:00:00', '{"info_buyRequest":{"qty":1}}');
        // The jobs as the pilot store left them: stopped before an error.
        $this->seedFailedJob(Job::TYPE_CUSTOMERS, "The store that was requested wasn't found.");
        $this->seedFailedJob(Job::TYPE_ORDERS, 'Unable to unserialize value. Error: Control character error');

        // Run again.
        $this->jobManager->start(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $tick = $this->tick();
        for ($run = 0; $run < 10 && $this->jobManager->nextActive() !== null; $run++) {
            $tick->execute();
        }

        $jobs = $this->connection->fetchAll(
            $this->connection->select()->from(JobResource::TABLE_NAME)->where('status != ?', Job::STATUS_FAILED)
        );
        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            self::assertSame(Job::STATUS_COMPLETED, $job['status'], $job['job_type'] . ' import completes');
            self::assertSame('3', (string)$job['processed_count'], $job['job_type'] . ': every row went');
            self::assertSame('0', (string)$job['failed_count'], $job['job_type'] . ': no row was skipped');
            self::assertNull($job['error_message']);
        }

        $customers = $this->queued('customers');
        self::assertSame([1, 2, 3], array_keys($customers));
        self::assertSame('et', $customers[1]['language']);
        self::assertArrayNotHasKey('language', $customers[2], 'The customer of the missing store goes without one');
        self::assertSame('et', $customers[3]['language']);

        $orders = $this->queued('orders');
        self::assertSame([100000001, 100000002, 100000003], array_keys($orders));
        self::assertEquals(
            [['sku' => 'SKU-2', 'qty' => 1, 'unit_price' => 20, 'line_total' => 20]],
            $orders[100000002]['items'],
            'The order with the unreadable item options goes with its line'
        );
        self::assertSame('2022-04-06T10:00:00Z', $orders[100000002]['ordered_at']);

        self::assertSame([
            [
                'Customer sent without a language: its store view no longer exists',
                ['customer_id' => 2, 'store_id' => self::MISSING_STORE_ID],
            ],
            ['Order sent without the item options Magento could not read', ['order_id' => 2]],
        ], array_values(array_filter(
            $this->logged,
            static fn (array $entry): bool => $entry[0] !== 'Backfill job completed'
        )), 'The log names each row by its id, and nothing else of it');
    }

    private function tick(): BackfillTick
    {
        $logger = $this->createMock(Logger::class);
        $logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logged[] = [$message, $context];
        });
        $ingestQueue = $this->objectManager->create(IngestQueue::class);
        $skippedRow = new SkippedRow($logger);
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isRefused')->willReturn(false);

        $customers = new EngineCustomersProcessor(
            $this->jobManager,
            $this->customerRepository(),
            $this->objectManager->create(SearchCriteriaBuilder::class),
            $this->objectManager->create(SortOrderBuilder::class),
            new CustomerPayloadBuilder(new LanguageResolver($this->storeLocales()), $logger),
            $ingestQueue,
            $skippedRow
        );
        $orders = new EngineOrdersProcessor(
            $this->jobManager,
            $this->orderCollectionFactory(),
            new OrderPayloadBuilder(
                $this->objectManager->get(ResourceConnection::class),
                $this->orderItemCollectionFactory(),
                $logger
            ),
            $ingestQueue,
            $skippedRow
        );

        return new BackfillTick($this->jobManager, $engineSettings, $logger, [
            Job::TYPE_CUSTOMERS . ':' . Job::TARGET_ENGINE => $customers,
            Job::TYPE_ORDERS . ':' . Job::TARGET_ENGINE => $orders,
        ]);
    }

    /**
     * Customers 1-3; customer 2 keeps the id of a store view that was deleted.
     */
    private function customerRepository(): CustomerRepositoryInterface
    {
        $customers = [];
        foreach ([1 => 1, 2 => self::MISSING_STORE_ID, 3 => 1] as $id => $storeId) {
            $customer = $this->createMock(CustomerInterface::class);
            $customer->method('getId')->willReturn($id);
            $customer->method('getEmail')->willReturn('customer' . $id . '@example.com');
            $customer->method('getStoreId')->willReturn($storeId);
            $customer->method('getAddresses')->willReturn([]);
            $customers[$id] = $customer;
        }

        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getList')->willReturnCallback(
            function (SearchCriteriaInterface $criteria) use ($customers): CustomerSearchResultsInterface {
                $after = (int)$criteria->getFilterGroups()[0]->getFilters()[0]->getValue();
                $page = array_values(array_filter(
                    $customers,
                    static fn (int $id): bool => $id > $after,
                    ARRAY_FILTER_USE_KEY
                ));
                $results = $this->createMock(CustomerSearchResultsInterface::class);
                $results->method('getItems')->willReturn($page);
                $results->method('getTotalCount')->willReturn(count($page));

                return $results;
            }
        );

        return $repository;
    }

    /**
     * The store locale lookup: Magento's StoreRepository throws this for a
     * store id it does not have, and ScopeConfig passes it on.
     */
    private function storeLocales(): ScopeConfigInterface
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static function (string $path, string $scope, mixed $storeId): string {
                if ($scope === ScopeInterface::SCOPE_STORE && (int)$storeId === self::MISSING_STORE_ID) {
                    throw new NoSuchEntityException(
                        __("The store that was requested wasn't found. Verify the store and try again.")
                    );
                }

                return 'et_EE';
            }
        );

        return $scopeConfig;
    }

    /**
     * The orders, read from sales_order the way the import pages through
     * them; each order's getItems() loads its lines the way Magento's
     * Order::getItems() does — a collection load that unserializes every
     * line's product_options.
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
        // Order lines without Magento's Order\Item constructor, whose
        // dependencies the harness does not have; the collection fills them.
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

    private function seedOrder(int $entityId, string $createdAt, string $productOptions): void
    {
        $this->connection->insert('sales_order', [
            'entity_id' => $entityId,
            'increment_id' => sprintf('10000000%d', $entityId),
            'state' => Order::STATE_COMPLETE,
            'customer_email' => 'customer' . $entityId . '@example.com',
            'grand_total' => $entityId * 10,
            'order_currency_code' => 'EUR',
            'created_at' => $createdAt,
        ]);
        $this->connection->insert('sales_order_item', [
            'order_id' => $entityId,
            'sku' => 'SKU-' . $entityId,
            'product_type' => 'simple',
            'qty_ordered' => 1,
            'row_total' => $entityId * 10,
            'row_total_incl_tax' => $entityId * 10,
            'product_options' => $productOptions,
        ]);
    }

    private function seedFailedJob(string $jobType, string $error): void
    {
        $this->connection->insert(JobResource::TABLE_NAME, [
            'job_type' => $jobType,
            'target' => Job::TARGET_ENGINE,
            'website_id' => Job::ENGINE_WEBSITE_ID,
            'status' => Job::STATUS_FAILED,
            'total_count' => 3,
            'processed_count' => 1,
            'cursor_value' => '1',
            'error_message' => $error,
        ]);
    }

    /**
     * The queued rows of one domain, keyed by their entity id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queued(string $domain): array
    {
        $rows = $this->connection->fetchAll(
            $this->connection->select()->from(IngestEventResource::TABLE_NAME)
                ->where('domain = ?', $domain)->order('id ASC')
        );
        $queued = [];
        foreach ($rows as $row) {
            $queued[(int)$row['entity_id']] = json_decode((string)$row['payload'], true);
        }

        return $queued;
    }
}
