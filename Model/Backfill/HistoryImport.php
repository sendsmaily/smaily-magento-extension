<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;

/**
 * The store's history import to Campaign Intelligence (contract v1.20.0
 * §25, CON-241): the catalog, the customers in scope and the orders, sent
 * newest first — the orders of the last `start_months` months (the start
 * part), then older ones down to `horizon_months` back; nothing older.
 *
 * One orders import is one history import: its window is fixed when the
 * import begins — the engine's `config.history` read then (HistoryConfig),
 * the start of the start part and of the horizon counted back from that
 * moment, and the orders it covers are those placed before it (a newer
 * order goes through the live sync). It is kept in one Magento flag with
 * the counts the progress report needs and the report's own state
 * (HistoryProgress); a new orders import replaces it.
 *
 * @phpstan-type State array{
 *     import_id: string,
 *     orders_job_id: int,
 *     start_months: int,
 *     horizon_months: int,
 *     order: string,
 *     until: string,
 *     start_from: string,
 *     horizon: string,
 *     start_expected: int,
 *     orders_expected: int,
 *     buyers_expected: int,
 *     start_part_queued_to: int|null,
 *     start_part_done: bool,
 *     reported_phase: string|null,
 *     reported_at: int,
 *     failed_at: int
 * }
 */
class HistoryImport
{
    public const FLAG = 'smaily_connect_history_import';

    /** The order states a purchase is in (contract §25 `buyers`: completed or processing). */
    private const PURCHASE_STATES = [Order::STATE_NEW, Order::STATE_PROCESSING, Order::STATE_COMPLETE];

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly HistoryConfig $historyConfig,
        private readonly JobManager $jobManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * The current (or last) history import, null when none has begun.
     *
     * @return State|null
     */
    public function state(): ?array
    {
        $state = $this->flagManager->getFlagData(self::FLAG);

        return is_array($state) && isset($state['orders_job_id']) ? $state : null;
    }

    /**
     * Begin the history import of this orders import, once: a second call
     * for the same job answers the state the first one stored.
     *
     * @return State
     */
    public function begin(Job $ordersJob): array
    {
        $state = $this->state();
        if ($state !== null && (int)$state['orders_job_id'] === (int)$ordersJob->getId()) {
            return $state;
        }

        $history = $this->historyConfig->forNewImport();
        $now = (int)$this->dateTime->gmtTimestamp();
        $until = gmdate('Y-m-d H:i:s', $now);
        $startFrom = $this->monthsBefore($now, $history['start_months']);
        $horizon = $this->monthsBefore($now, $history['horizon_months']);
        $state = [
            'import_id' => sprintf('mag-%s-%d', gmdate('Y-m-d', $now), (int)$ordersJob->getId()),
            'orders_job_id' => (int)$ordersJob->getId(),
            'start_months' => $history['start_months'],
            'horizon_months' => $history['horizon_months'],
            'order' => $history['order'],
            'until' => $until,
            'start_from' => $startFrom,
            'horizon' => $horizon,
            'start_expected' => $this->countOrders($startFrom, $until),
            'orders_expected' => $this->countOrders($horizon, $until),
            'buyers_expected' => $this->countBuyers($horizon, $until),
            'start_part_queued_to' => null,
            'start_part_done' => false,
            'reported_phase' => null,
            'reported_at' => 0,
            'failed_at' => 0,
        ];
        $this->flagManager->saveFlag(self::FLAG, $state);

        return $state;
    }

    /**
     * Begin the history import of the orders import that is queued or
     * running, if any — for the tick, before it runs the catalog and the
     * customers imports ahead of it, so the engine hears `started` first.
     */
    public function beginQueued(): void
    {
        $ordersJob = $this->jobManager->findActive(Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        if ($ordersJob !== null) {
            $this->begin($ordersJob);
        }
    }

    /**
     * Store changes to the current history import's state.
     *
     * @param array<string, mixed> $changes
     * @return State
     */
    public function update(array $changes): array
    {
        $state = array_merge((array)$this->state(), $changes);
        $this->flagManager->saveFlag(self::FLAG, $state);

        /** @var State $state */
        return $state;
    }

    /**
     * The oldest order time inside the horizon now (GMT), for the customers
     * import: the current import's horizon months, else the stored ones.
     */
    public function horizonNow(): string
    {
        $months = $this->state()['horizon_months'] ?? $this->historyConfig->stored()['horizon_months'];

        return $this->monthsBefore((int)$this->dateTime->gmtTimestamp(), (int)$months);
    }

    /**
     * A page of the orders an orders import sends, newest first: placed from
     * $from to $until and before the cursor ("created_at|entity_id", the
     * last order of the page before). A cursor of an earlier version (a bare
     * entity id) starts from the newest order again; the engine updates an
     * order it already has.
     *
     * @return list<array{entity_id: string, created_at: string}>
     */
    public function ordersPage(string $from, string $until, string $cursor, int $limit): array
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $this->ordersSelect($from, $until)
            ->columns(['entity_id' => 'o.entity_id', 'created_at' => 'o.created_at'])
            ->order(['o.created_at DESC', 'o.entity_id DESC'])
            ->limit($limit);
        $position = explode('|', $cursor);
        if (count($position) === 2) {
            [$createdAt, $entityId] = $position;
            $select->where('o.created_at <= ?', $createdAt)
                ->where(
                    $connection->quoteInto('o.created_at < ?', $createdAt)
                    . ' OR ' . $connection->quoteInto('o.entity_id < ?', (int)$entityId)
                );
        }

        /** @var list<array{entity_id: string, created_at: string}> $rows */
        $rows = $connection->fetchAll($select);

        return $rows;
    }

    /**
     * The orders an orders import sends: placed from $from to $until, in a
     * state the engine takes (OrderPayloadBuilder::STATUS_MAP).
     */
    private function ordersSelect(string $from, string $until): Select
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('sales_order')], [])
            ->where('o.created_at >= ?', $from)
            ->where('o.created_at <= ?', $until)
            ->where('o.state IN (?)', array_keys(OrderPayloadBuilder::STATUS_MAP));
    }

    private function countOrders(string $from, string $until): int
    {
        $select = $this->ordersSelect($from, $until)->columns(['cnt' => new \Zend_Db_Expr('COUNT(*)')]);

        return (int)$this->resourceConnection->getConnection()->fetchOne($select);
    }

    /**
     * Distinct buyers with a purchase from $from to $until (contract §25
     * `entities.buyers`), by email: a guest buyer counts too.
     */
    private function countBuyers(string $from, string $until): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName('sales_order'),
                ['cnt' => new \Zend_Db_Expr('COUNT(DISTINCT customer_email)')]
            )
            ->where('created_at >= ?', $from)
            ->where('created_at <= ?', $until)
            ->where('state IN (?)', self::PURCHASE_STATES);

        return (int)$connection->fetchOne($select);
    }

    /**
     * $months calendar months before $timestamp, GMT. A day the earlier
     * month lacks rolls forward (31 August − 6 months is 3 March), never
     * back past the engine's own horizon.
     */
    private function monthsBefore(int $timestamp, int $months): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))
            ->sub(new \DateInterval('P' . $months . 'M'))
            ->format('Y-m-d H:i:s');
    }
}
