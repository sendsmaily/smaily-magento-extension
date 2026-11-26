<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\ImportClock;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Engine\Websites;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SalesOrderHarness;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-421 (contract v1.36.1 §5, one currency per store): every amount on an
 * order goes in the store's default currency, Magento's base currency. An
 * order the shopper paid in another currency goes at its own rate at the
 * time of the order — the base amounts Magento stored with it — and an
 * order in the base currency goes as before. The live order sync and the
 * history import build the order the same way.
 *
 * Real here: the sales_order and sales_order_item tables, Magento's order
 * model and its order-line collection, the order payload builder, the job
 * table, the ingest queue and the cron tick.
 */
class OrderCurrencyTest extends IntegrationTestCase
{
    use SalesOrderHarness;
    use EngineConnectionsStub;

    /** USD per EUR on the day of the foreign-currency order. */
    private const RATE = 1.25;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/OrderItemCollectionFactory.php';
        $schema = new SchemaInstaller($this->connection);
        $schema->createSalesOrder();
        $schema->createSalesOrderItem();
        $schema->createFlag();
    }

    protected function tearDown(): void
    {
        $schema = new SchemaInstaller($this->connection);
        $schema->dropSalesOrderItem();
        $schema->dropSalesOrder();
        parent::tearDown();
    }

    public function testAnOrderInAnotherCurrencyGoesInTheBaseCurrencyAtItsOwnRate(): void
    {
        $this->seedOrder(1, 'USD', self::RATE);

        $payload = $this->builder()->build($this->loadOrder(1));

        self::assertNotNull($payload);
        self::assertEquals($this->expectedInEur(1), $this->amounts($payload));
    }

    public function testAnOrderInTheBaseCurrencyGoesAsBefore(): void
    {
        $this->seedOrder(1, 'EUR', 1.0);

        $payload = $this->builder()->build($this->loadOrder(1));

        self::assertNotNull($payload);
        self::assertEquals($this->expectedInEur(1), $this->amounts($payload));
    }

    public function testTheHistoryImportSendsEveryOrderInTheBaseCurrency(): void
    {
        $this->seedOrder(1, 'USD', self::RATE);
        $this->seedOrder(2, 'EUR', 1.0);
        $jobManager = $this->objectManager->create(JobManager::class);
        $jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);

        $tick = $this->tick($jobManager);
        for ($run = 0; $run < 10 && $jobManager->nextActive() !== null; $run++) {
            $tick->execute();
        }

        $queued = $this->queuedOrders();
        self::assertSame([100000001, 100000002], array_keys($queued));
        self::assertEquals($this->expectedInEur(1), $this->amounts($queued[100000001]));
        self::assertEquals($this->expectedInEur(2), $this->amounts($queued[100000002]));
    }

    /**
     * The order's amounts in EUR, the base currency, as the engine is sent
     * them: one line of two at 20.00 EUR each with a 4.00 EUR discount,
     * 6.00 EUR shipping. The order's id scales them so two orders differ.
     *
     * @return array<string, mixed>
     */
    private function expectedInEur(int $entityId): array
    {
        return [
            'currency' => 'EUR',
            'total_amount' => 42.0 * $entityId,
            'discount_amount' => 4.0 * $entityId,
            'items' => [[
                'sku' => 'SKU-1',
                'qty' => 2,
                'unit_price' => 20.0 * $entityId,
                'line_total' => 36.0 * $entityId,
                'discount_amount' => 4.0 * $entityId,
            ]],
        ];
    }

    /**
     * The currency and every amount of a payload (a queued one is JSON, so
     * a whole number comes back an int: compare with assertEquals()).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function amounts(array $payload): array
    {
        return array_intersect_key(
            $payload,
            array_flip(['currency', 'total_amount', 'discount_amount', 'items'])
        );
    }

    /**
     * An order whose base amounts are in EUR and whose amounts are in the
     * currency the shopper paid in, at $rate units of it per EUR, as Magento
     * stores them.
     */
    private function seedOrder(int $entityId, string $orderCurrency, float $rate): void
    {
        $base = [
            'grand_total' => 42.0 * $entityId,
            'discount_amount' => -4.0 * $entityId,
            'row_total' => 40.0 * $entityId,
            'row_total_incl_tax' => 40.0 * $entityId,
            'item_discount' => 4.0 * $entityId,
        ];
        $paid = array_map(static fn (float $amount): float => round($amount * $rate, 4), $base);

        $this->connection->insert('sales_order', [
            'entity_id' => $entityId,
            'increment_id' => sprintf('10000000%d', $entityId),
            'state' => Order::STATE_COMPLETE,
            'customer_email' => 'customer' . $entityId . '@example.com',
            'created_at' => gmdate('Y-m-d H:i:s', strtotime(sprintf('-%d days', 10 * $entityId))),
            'base_currency_code' => 'EUR',
            'global_currency_code' => 'EUR',
            'store_currency_code' => 'EUR',
            'order_currency_code' => $orderCurrency,
            'base_to_order_rate' => $rate,
            'base_to_global_rate' => 1,
            'base_grand_total' => $base['grand_total'],
            'base_discount_amount' => $base['discount_amount'],
            'grand_total' => $paid['grand_total'],
            'discount_amount' => $paid['discount_amount'],
        ]);
        $this->connection->insert('sales_order_item', [
            'order_id' => $entityId,
            'sku' => 'SKU-1',
            'product_type' => 'simple',
            'qty_ordered' => 2,
            'base_row_total' => $base['row_total'],
            'base_row_total_incl_tax' => $base['row_total_incl_tax'],
            'base_discount_amount' => $base['item_discount'],
            'row_total' => $paid['row_total'],
            'row_total_incl_tax' => $paid['row_total_incl_tax'],
            'discount_amount' => $paid['item_discount'],
        ]);
    }

    /**
     * The order of its sales_order row, its lines loaded by Magento's
     * order-line collection (SalesOrderHarness).
     */
    private function loadOrder(int $entityId): Order
    {
        return $this->order((array)$this->connection->fetchRow(
            $this->connection->select()->from('sales_order')->where('entity_id = ?', $entityId)
        ));
    }

    private function builder(): OrderPayloadBuilder
    {
        return new OrderPayloadBuilder(
            $this->objectManager->get(ResourceConnection::class),
            $this->orderItemCollectionFactory(),
            $this->createMock(Websites::class),
            $this->createMock(Logger::class)
        );
    }

    private function tick(JobManager $jobManager): BackfillTick
    {
        $logger = $this->createMock(Logger::class);
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isRefused')->willReturn(false);
        $historyConfig = $this->createMock(HistoryConfig::class);
        $historyConfig->method('forNewImport')->willReturn(HistoryConfig::FALLBACK);
        $historyConfig->method('stored')->willReturn(HistoryConfig::FALLBACK);
        $historyImport = $this->objectManager->create(HistoryImport::class, [
            'historyConfig' => $historyConfig,
            'connections' => $this->oneEngineConnection($engineSettings),
        ]);

        $orders = new EngineOrdersProcessor(
            $jobManager,
            $this->orderCollectionFactory(),
            $this->builder(),
            $this->objectManager->create(IngestQueue::class),
            new SkippedRow($logger),
            $historyImport,
            new ImportClock(),
            $this->engineJobConnection($this->oneEngineConnection($engineSettings), $jobManager)
        );

        return new BackfillTick(
            $jobManager,
            $engineSettings,
            $logger,
            $historyImport,
            $this->createMock(HistoryProgress::class),
            [Job::TYPE_ORDERS . ':' . Job::TARGET_ENGINE => $orders]
        );
    }

    /**
     * The queued order rows, keyed by their entity id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queuedOrders(): array
    {
        $rows = $this->connection->fetchAll(
            $this->connection->select()->from(IngestEventResource::TABLE_NAME)
                ->where('domain = ?', 'orders')->order('entity_id ASC')
        );
        $queued = [];
        foreach ($rows as $row) {
            $queued[(int)$row['entity_id']] = json_decode((string)$row['payload'], true);
        }

        return $queued;
    }
}
