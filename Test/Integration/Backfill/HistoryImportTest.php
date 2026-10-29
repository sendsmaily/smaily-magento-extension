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
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-241 (contract v1.20.0 §25): a history import fixes its window when it
 * begins — the start part's and the horizon's first moment, counted back
 * from then, and the orders placed up to then — and its orders are read
 * newest first, the start part before the older ones, from a cursor that
 * resumes anywhere; an order placed during the import is never read (the
 * live sync sends it), and no order older than the horizon is.
 *
 * Real here: the sales_order table, the flag table and the SQL.
 */
class HistoryImportTest extends IntegrationTestCase
{
    private const MONTH = 30 * 86400;

    private HistoryImport $historyImport;

    private JobManager $jobManager;

    protected function setUp(): void
    {
        parent::setUp();
        $schema = new SchemaInstaller($this->connection);
        $schema->createSalesOrder();
        $schema->createFlag();
        $historyConfig = $this->createMock(HistoryConfig::class);
        $historyConfig->method('forNewImport')->willReturn(HistoryConfig::FALLBACK);
        $historyConfig->method('stored')->willReturn(HistoryConfig::FALLBACK);
        $this->historyImport = $this->objectManager->create(
            HistoryImport::class,
            ['historyConfig' => $historyConfig, 'dateTime' => $this->clock]
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
        $state = $this->historyImport->begin($job);

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
        self::assertSame($state, $this->historyImport->begin($job), 'Begun once per orders import');

        $this->jobManager->requestCancel(Job::TYPE_ORDERS, Job::TARGET_ENGINE);
        $next = $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertSame((int)$next->getId(), $this->historyImport->begin($next)['orders_job_id'], 'Run again begins anew');
    }

    /**
     * Newest first, two at a time: the start part, then — from the same
     * cursor — the older orders down to the horizon. An order placed after
     * the import began is not read.
     */
    public function testTheOrdersAreReadNewestFirstTheStartPartBeforeTheRest(): void
    {
        $state = $this->historyImport->begin(
            $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
        );
        $this->clock->travel(60);
        $this->seed(9, 0, 'new@example.invalid');

        $cursor = '';
        $read = [];
        foreach ([$state['start_from'], $state['horizon']] as $from) {
            while ($page = $this->historyImport->ordersPage($from, $state['until'], $cursor, 2)) {
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
            $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
        );
        $at = gmdate('Y-m-d H:i:s', $this->clock->now() - 2 * self::MONTH);

        self::assertSame(
            ['6', '5', '3', '2'],
            array_column($this->historyImport->ordersPage($state['horizon'], $state['until'], $at . '|7', 10), 'entity_id')
        );
        self::assertSame(
            ['8', '7'],
            array_column($this->historyImport->ordersPage($state['horizon'], $state['until'], '4512', 2), 'entity_id')
        );
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
