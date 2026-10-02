<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Truthful operational numbers for the admin dashboard. Every figure is a
 * real local query against the delivery queues — nothing is estimated or
 * fabricated. Retention caveat: the queue janitor keeps sent rows for 30
 * days, so "delivered" counters are rolling-window numbers, and the tiles
 * are labelled accordingly.
 */
class DashboardStats
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Delivered contact syncs still in the 30-day retention window: rows
     * that reached Smaily (PRO-3634). The queue also closes a row as `sent`
     * when it skips it (reason in `last_error`) or withdraws it
     * (`last_response` = CANCELLED_RESPONSE); the Log reads those as
     * Skipped and Withdrawn, and they are not counted here.
     */
    public function contactSyncsDelivered(): int
    {
        return $this->count(EventResource::TABLE_NAME, [
            'status = ?' => Event::STATUS_SENT,
            'event_type = ?' => EventType::CONTACT_SYNC,
            "COALESCE(last_error, '') = ?" => '',
            "COALESCE(last_response, '') <> ?" => EventQueue::CANCELLED_RESPONSE,
        ]);
    }

    /**
     * Delivered catalog items still in the 30-day retention window. The
     * ingest queue marks a row `sent` only on the engine's answer — it
     * never skips or withdraws one.
     */
    public function catalogItemsDelivered(): int
    {
        return $this->count(IngestEventResource::TABLE_NAME, [
            'status = ?' => 'sent',
            'domain = ?' => 'catalog',
        ]);
    }

    /**
     * Rows queued today (UTC) across both delivery queues that are still
     * waiting to be sent — the first attempt or a retry (PRO-3628). A row
     * sent, failed or being sent right now is not counted.
     */
    public function queuedToday(): int
    {
        $midnight = $this->dateTime->gmtDate('Y-m-d 00:00:00');
        $total = 0;
        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $total += $this->count($table, [
                'created_at >= ?' => $midnight,
                'status = ?' => 'pending',
            ]);
        }

        return $total;
    }

    /**
     * The latest queue rows from both queues, newest first.
     *
     * @return array<int, array{source: string, type: string, entity_id: string,
     *     status: string, updated_at: string}>
     */
    public function recentActivity(int $limit = 10): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rows = [];

        $eventSelect = $connection->select()
            ->from($this->resourceConnection->getTableName(EventResource::TABLE_NAME), [
                'type' => 'event_type',
                'entity_id',
                'status',
                'updated_at',
            ])
            ->order('updated_at DESC')
            ->order('id DESC')
            ->limit($limit);
        foreach ($connection->fetchAll($eventSelect) as $row) {
            $rows[] = ['source' => 'smaily'] + array_map('strval', $row);
        }

        $ingestSelect = $connection->select()
            ->from($this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME), [
                'type' => 'domain',
                'entity_id',
                'status',
                'updated_at',
            ])
            ->order('updated_at DESC')
            ->order('id DESC')
            ->limit($limit);
        foreach ($connection->fetchAll($ingestSelect) as $row) {
            $rows[] = ['source' => 'intelligence'] + array_map('strval', $row);
        }

        usort($rows, static fn (array $a, array $b) => strcmp($b['updated_at'], $a['updated_at']));

        return array_slice($rows, 0, $limit);
    }

    /**
     * @param array<string, string> $where
     */
    private function count(string $table, array $where): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName($table), ['cnt' => 'COUNT(*)']);
        foreach ($where as $condition => $value) {
            $select->where($condition, $value);
        }

        return (int)$connection->fetchOne($select);
    }
}
