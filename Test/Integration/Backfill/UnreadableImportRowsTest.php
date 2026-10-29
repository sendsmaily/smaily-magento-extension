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
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\EngineCustomersProcessor;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SalesOrderHarness;
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
    use SalesOrderHarness;

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

    public function testRunAgainGoesPastTheUnreadableRowsAndCompletes(): void
    {
        // Inside the history horizon (CON-241), which counts back from now.
        $unreadableAt = gmdate('Y-m-d H:i:s', strtotime('-30 months'));
        $this->seedOrder(1, gmdate('Y-m-d H:i:s', strtotime('-50 months')), '{"info_buyRequest":{"qty":1}}');
        $this->seedOrder(2, $unreadableAt, self::UNREADABLE_OPTIONS);
        $this->seedOrder(3, gmdate('Y-m-d H:i:s', strtotime('-29 months')), '{"info_buyRequest":{"qty":1}}');
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
        self::assertSame([100000003, 100000002, 100000001], array_keys($orders), 'Newest first');
        self::assertEquals(
            [['sku' => 'SKU-2', 'qty' => 1, 'unit_price' => 20, 'line_total' => 20]],
            $orders[100000002]['items'],
            'The order with the unreadable item options goes with its line'
        );
        self::assertSame(str_replace(' ', 'T', $unreadableAt) . 'Z', $orders[100000002]['ordered_at']);

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

        $historyConfig = $this->createMock(HistoryConfig::class);
        $historyConfig->method('forNewImport')->willReturn(HistoryConfig::FALLBACK);
        $historyConfig->method('stored')->willReturn(HistoryConfig::FALLBACK);
        $historyImport = $this->objectManager->create(HistoryImport::class, ['historyConfig' => $historyConfig]);
        // The customers in scope: all three (EngineCustomerScope has its own test).
        $scope = $this->createMock(EngineCustomerScope::class);
        $scope->method('count')->willReturn(3);
        $scope->method('page')->willReturnCallback(
            static fn (string $horizon, int $afterId): array => array_values(array_filter(
                [1, 2, 3],
                static fn (int $id): bool => $id > $afterId
            ))
        );

        $customers = new EngineCustomersProcessor(
            $this->jobManager,
            $this->customerRepository(),
            $this->objectManager->create(SearchCriteriaBuilder::class),
            new CustomerPayloadBuilder(new LanguageResolver($this->storeLocales()), $logger),
            $ingestQueue,
            $skippedRow,
            $scope,
            $historyImport
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
            $skippedRow,
            $historyImport
        );

        return new BackfillTick($this->jobManager, $engineSettings, $logger, $historyImport, $this->createMock(HistoryProgress::class), [
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
                $ids = (array)$criteria->getFilterGroups()[0]->getFilters()[0]->getValue();
                $page = array_values(array_intersect_key($customers, array_flip(array_map('intval', $ids))));
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
