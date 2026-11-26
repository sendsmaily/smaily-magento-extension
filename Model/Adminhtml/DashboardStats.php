<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Log\EntityLabel;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection as LogCollection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Truthful operational numbers for the admin dashboard. Every figure is a
 * real local query against the delivery queues — nothing is estimated or
 * fabricated. Retention caveat: the queue janitor keeps sent rows for 30
 * days, so "delivered" counters are rolling-window numbers, and the tiles
 * are labelled accordingly.
 *
 * A website id (the Dashboard of a separate store, CON-281) narrows the
 * marketing queue — the rows sent through that website's Smaily account —
 * to the website's rows; the Campaign Intelligence queue stays the whole
 * installation's, as its connection does.
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
     * that reached Smaily (PRO-3634), the rows the Log shows as Sent. The
     * queue also closes a row as `sent` when it skips or withdraws it; the
     * Log reads those as Skipped and Withdrawn
     * (LogCollection::smailyStatusExpression()), and they are not counted.
     */
    public function contactSyncsDelivered(?int $websiteId = null): int
    {
        $status = LogCollection::smailyStatusExpression($this->resourceConnection->getConnection(), 'q');

        return $this->count(EventResource::TABLE_NAME, [
            'event_type = ?' => EventType::CONTACT_SYNC,
            $status . ' = ?' => Event::STATUS_SENT,
        ] + $this->websiteCondition($websiteId));
    }

    /**
     * Delivered catalog items still in the 30-day retention window, the
     * rows the Log shows as Sent. The ingest queue also closes a catalog row
     * as `sent` when a newer row of the product supersedes it (CON-423); the
     * Log reads it as Skipped (LogCollection::intelligenceStatusExpression()),
     * and it is not counted.
     */
    public function catalogItemsDelivered(): int
    {
        $status = LogCollection::intelligenceStatusExpression($this->resourceConnection->getConnection(), 'q');

        return $this->count(IngestEventResource::TABLE_NAME, [
            'domain = ?' => 'catalog',
            $status . ' = ?' => IngestEvent::STATUS_SENT,
        ]);
    }

    /**
     * Rows queued today (UTC) across both delivery queues that are still
     * waiting to be sent — the first attempt or a retry (PRO-3628). A row
     * sent, failed or being sent right now is not counted.
     */
    public function queuedToday(?int $websiteId = null): int
    {
        $midnight = $this->dateTime->gmtDate('Y-m-d 00:00:00');
        $total = 0;
        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $total += $this->count($table, [
                'created_at >= ?' => $midnight,
                'status = ?' => 'pending',
            ] + ($table === EventResource::TABLE_NAME ? $this->websiteCondition($websiteId) : []));
        }

        return $total;
    }

    /**
     * The latest queue rows from both queues, newest first. A row's status
     * is the one the Log shows (PRO-3642; an ingest row's too, CON-423):
     * Skipped and Withdrawn are read off the row the same way, never shown
     * as Sent; a marketing-queue row's Entity too (PRO-3765, EntityLabel).
     *
     * @return array<int, array{source: string, type: string, entity_id: string,
     *     status: string, updated_at: string}>
     */
    public function recentActivity(int $limit = 10, ?int $websiteId = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $rows = [];

        $eventSelect = $connection->select()
            ->from(['q' => $this->resourceConnection->getTableName(EventResource::TABLE_NAME)], [
                'type' => 'q.event_type',
                'entity_id' => 'q.entity_id',
                'status' => LogCollection::smailyStatusExpression($connection, 'q'),
                'updated_at' => 'q.updated_at',
            ])
            ->order('q.updated_at DESC')
            ->order('q.id DESC')
            ->limit($limit);
        if ($websiteId !== null) {
            $eventSelect->where('q.website_id = ?', $websiteId);
        }
        foreach ($connection->fetchAll($eventSelect) as $row) {
            $row = array_map('strval', $row);
            $row['entity_id'] = EntityLabel::forDisplay($row['type'], $row['entity_id']);
            $rows[] = ['source' => 'smaily'] + $row;
        }

        $ingestSelect = $connection->select()
            ->from(['q' => $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME)], [
                'type' => 'domain',
                'entity_id',
                'status' => LogCollection::intelligenceStatusExpression($connection, 'q'),
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
     * The marketing queue's condition for a website's rows; none for null.
     *
     * @return array<string, int>
     */
    private function websiteCondition(?int $websiteId): array
    {
        return $websiteId === null ? [] : ['q.website_id = ?' => $websiteId];
    }

    /**
     * @param array<string, string|int> $where
     */
    private function count(string $table, array $where): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(['q' => $this->resourceConnection->getTableName($table)], ['cnt' => 'COUNT(*)']);
        foreach ($where as $condition => $value) {
            $select->where($condition, $value);
        }

        return (int)$connection->fetchOne($select);
    }
}
