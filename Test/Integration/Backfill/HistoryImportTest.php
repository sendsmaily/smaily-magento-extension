<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Sales\Model\Order;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-241 (contract v1.20.0 §25): a history import fixes its window when it
 * begins — the start part's and the horizon's first moment, counted back
 * from then, and the orders placed up to then — and its orders are read
 * newest first, the start part before the older ones, from a cursor that
 * resumes anywhere; an order placed during the import is never read (the
 * live sync sends it), and no order older than the horizon is.
 *
 * Real here: the sales_order table, the flag table and the SQL.
 *
 * CON-237: each Campaign Intelligence connection has its own history
 * import — its own flag, its orders those of its store views. Under one
 * store the primary reads its orders with the query it always read.
 */
class HistoryImportTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private const MONTH = 30 * 86400;

    /** The orders page of a store run as one store, as it was read before CON-237. */
    private const ONE_STORE_PAGE_SQL = 'SELECT `o`.`entity_id`, `o`.`created_at` FROM `sales_order` AS `o`'
        . " WHERE (o.created_at >= '<from>') AND (o.created_at <= '<until>')"
        . " AND (o.state IN ('complete', 'new', 'processing', 'canceled', 'closed'))"
        . " ORDER BY `o`.`created_at` DESC, `o`.`entity_id` DESC\n LIMIT 2";

    private HistoryImport $historyImport;

    private JobManager $jobManager;

    /** The one connection of a store run as one store. */
    private Connection $one;

    /** Separate stores: the primary serves store views 1 and 2, the connection of website 2 store view 3. */
    private Connection $primary;

    private Connection $second;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = new SchemaInstaller($this->connection);
        $schema->createSalesOrder();
        $schema->createFlag();
        $historyConfig = $this->createMock(HistoryConfig::class);
        $historyConfig->method('forNewImport')->willReturn(HistoryConfig::FALLBACK);
        $historyConfig->method('stored')->willReturn(HistoryConfig::FALLBACK);
        $settings = $this->createMock(Settings::class);
        $this->one = $this->engineConnection(0, $settings, [1, 2], [1, 2, 3], 1, true);
        $this->primary = $this->engineConnection(0, $settings, [1], [1, 2], 1);
        $this->second = $this->engineConnection(2, $settings, [2], [3], 3);
        $this->historyImport = $this->objectManager->create(
            HistoryImport::class,
            [
                'historyConfig' => $historyConfig,
                'dateTime' => $this->clock,
                'connections' => $this->engineConnections([[$this->one, null]]),
            ]
        );
        $this->jobManager = $this->objectManager->create(JobManager::class);

        $this->seed(1, -70 * self::MONTH, 'old@example.invalid');
        $this->seed(2, -40 * self::MONTH, 'a@example.invalid');
        $this->seed(3, -20 * self::MONTH, 'b@example.invalid', Order::STATE_CANCELED);
        $this->seed(4, -10 * self::MONTH, 'c@example.invalid', Order::STATE_PENDING_PAYMENT);
        $this->seed(5, -10 * self::MONTH, 'c@example.invalid');
        // Two orders in the same second: the entity id breaks the tie.
        $this->seed(6, -2 * self::MONTH, 'd@example.invalid');
        $this->seed(7, -2 * self::MONTH, 'A@example.invalid');
        $this->seed(8, -86400, 'e@example.invalid', Order::STATE_PROCESSING);
    }

    protected function tearDown(): void
    {
        (new SchemaInstaller($this->connection))->dropSalesOrder();
        parent::tearDown();
    }

    public function testTheImportFixesItsWindowAndCountsWhatItSends(): void
    {
        $job = $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $state = $this->historyImport->begin($job, $this->one);

        self::assertSame(sprintf('mag-%s-%d', gmdate('Y-m-d', $this->clock->now()), $job->getId()), $state['import_id']);
        self::assertSame(gmdate('Y-m-d H:i:s', $this->clock->now()), $state['until']);
        self::assertSame(
            (new \DateTimeImmutable('@' . $this->clock->now()))->modify('-18 months')->format('Y-m-d H:i:s'),
            $state['start_from']
        );
        self::assertSame(
            (new \DateTimeImmutable('@' . $this->clock->now()))->modify('-60 months')->format('Y-m-d H:i:s'),
            $state['horizon']
        );
        self::assertSame(4, $state['start_expected'], 'Orders 5-8: order 4 waits for payment, never sent');
        self::assertSame(6, $state['orders_expected'], 'Orders 2, 3 and 5-8: order 1 is past the horizon');
        self::assertSame(4, $state['buyers_expected'], 'a, c, d, e: a cancelled order is no purchase, A is a');

        $this->clock->travel(600);
        self::assertSame($state, $this->historyImport->begin($job, $this->one), 'Begun once per orders import');

        $this->jobManager->requestCancel(Job::TYPE_ORDERS, Job::TARGET_ENGINE);
        $next = $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertSame(
            (int)$next->getId(),
            $this->historyImport->begin($next, $this->one)['orders_job_id'],
            'Run again begins anew'
        );
    }

    /**
     * Newest first, two at a time: the start part, then — from the same
     * cursor — the older orders down to the horizon. An order placed after
     * the import began is not read.
     */
    public function testTheOrdersAreReadNewestFirstTheStartPartBeforeTheRest(): void
    {
        $state = $this->historyImport->begin(
            $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID),
            $this->one
        );
        $this->clock->travel(60);
        $this->seed(9, 0, 'new@example.invalid');

        $cursor = '';
        $read = [];
        foreach ([$state['start_from'], $state['horizon']] as $from) {
            while ($page = $this->historyImport->ordersPage($from, $state['until'], $cursor, 2, $this->one)) {
                $read[] = array_map('intval', array_column($page, 'entity_id'));
                $last = end($page);
                $cursor = $last['created_at'] . '|' . $last['entity_id'];
            }
            $read[] = 'end of ' . ($from === $state['start_from'] ? 'the start part' : 'the horizon');
        }

        self::assertSame([[8, 7], [6, 5], 'end of the start part', [3, 2], 'end of the horizon'], $read);
    }

    /**
     * A cursor resumes where it stopped; a cursor an earlier version stored
     * (a bare entity id) starts from the newest order again.
     */
    public function testACursorResumesAndAnEarlierVersionsCursorStartsAgain(): void
    {
        $state = $this->historyImport->begin(
            $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID),
            $this->one
        );
        $at = gmdate('Y-m-d H:i:s', $this->clock->now() - 2 * self::MONTH);

        self::assertSame(
            ['6', '5', '3', '2'],
            array_column(
                $this->historyImport->ordersPage($state['horizon'], $state['until'], $at . '|7', 10, $this->one),
                'entity_id'
            )
        );
        self::assertSame(
            ['8', '7'],
            array_column(
                $this->historyImport->ordersPage($state['horizon'], $state['until'], '4512', 2, $this->one),
                'entity_id'
            )
        );
    }

    /**
     * CON-237: separate stores — each connection's history import counts
     * and reads the orders of its own store views and keeps its own state;
     * an order of a store view deleted since (store_id NULL) is neither's.
     */
    public function testEachConnectionsHistoryImportHoldsTheOrdersOfItsStoreViews(): void
    {
        foreach ([2 => 1, 3 => 3, 5 => 2, 6 => 3, 7 => 1] as $orderId => $storeId) {
            $this->connection->update('sales_order', ['store_id' => $storeId], 'entity_id = ' . $orderId);
        }
        $primaryJob = $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, 0);
        $secondJob = $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, 2);

        $primary = $this->historyImport->begin($primaryJob, $this->primary);
        $second = $this->historyImport->begin($secondJob, $this->second);

        $counts = static fn (array $state): array => [
            $state['start_expected'],
            $state['orders_expected'],
            $state['buyers_expected'],
        ];
        self::assertSame([2, 3, 2], $counts($primary), 'Orders 5 and 7; 2, 5 and 7; a and c');
        self::assertSame([1, 2, 1], $counts($second), 'Order 6; 3 and 6; d: a cancelled order is no purchase');
        self::assertSame((int)$primaryJob->getId(), $this->historyImport->state($this->primary)['orders_job_id'] ?? 0);
        self::assertSame((int)$secondJob->getId(), $this->historyImport->state($this->second)['orders_job_id'] ?? 0);
        self::assertSame(
            ['7', '5', '2'],
            array_column(
                $this->historyImport->ordersPage($primary['horizon'], $primary['until'], '', 10, $this->primary),
                'entity_id'
            )
        );
        self::assertSame(
            ['6', '3'],
            array_column(
                $this->historyImport->ordersPage($second['horizon'], $second['until'], '', 10, $this->second),
                'entity_id'
            )
        );
        self::assertNotNull($this->flagData(HistoryImport::FLAG));
        self::assertNotNull($this->flagData(HistoryImport::FLAG . '_c2'), 'Its own flag');
    }

    /**
     * CON-237, the single-connection golden test: under one store the
     * orders page is read with the query it always read — no store view
     * narrowing.
     */
    public function testOneStoreReadsTheOrdersPageWithTheQueryItAlwaysRead(): void
    {
        $state = $this->historyImport->begin(
            $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID),
            $this->one
        );
        $connection = $this->connection;
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            self::fail('The test connection keeps no query profile');
        }
        $profiler = $connection->getProfiler();
        $profiler->setEnabled(true);
        $profiler->clear();
        $this->historyImport->ordersPage($state['horizon'], $state['until'], '', 2, $this->one);
        $queries = $profiler->getQueryProfiles();
        $profiler->setEnabled(false);

        self::assertIsArray($queries);
        self::assertSame(self::ONE_STORE_PAGE_SQL, str_replace(
            [$state['horizon'], $state['until']],
            ['<from>', '<until>'],
            (string)end($queries)->getQuery()
        ));
    }

    /**
     * @return mixed the flag's data, null when there is none
     */
    private function flagData(string $code): mixed
    {
        $data = $this->connection->fetchOne(
            $this->connection->select()->from('flag', ['flag_data'])->where('flag_code = ?', $code)
        );

        return $data === false ? null : $data;
    }

    private function seed(int $id, int $offsetSeconds, string $email, string $state = Order::STATE_COMPLETE): void
    {
        $this->connection->insert('sales_order', [
            'entity_id' => $id,
            'increment_id' => (string)(100000000 + $id),
            'state' => $state,
            'customer_email' => $email,
            'grand_total' => 10,
            'order_currency_code' => 'EUR',
            'created_at' => $this->clockDate($offsetSeconds),
        ]);
    }
}
