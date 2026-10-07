<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Loads the queue rows the Log works on, normalized the way the Log reads
 * them: the per-queue type column becomes "type", the queue it came from
 * becomes "source", and a withdrawn or skipped row carries the derived
 * status the grid shows — so the drawer, the grid label and the status filter all read one
 * value. Both log actions that work on a single row — Details and Send
 * again — start here, and so does the mass retry's question to the guard.
 */
class QueueRowLoader
{
    /** Queue source -> [table, type column]. */
    private const SOURCES = [
        Collection::SOURCE_SMAILY => [EventResource::TABLE_NAME, 'event_type'],
        Collection::SOURCE_INTELLIGENCE => [IngestEventResource::TABLE_NAME, 'domain'],
    ];

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * The row a composite log id ("smaily-<id>" / "intelligence-<id>")
     * addresses, with its own numeric id on it.
     *
     * @return array<string, mixed>|null
     */
    public function load(string $logId): ?array
    {
        [$source, $id] = Collection::splitLogId($logId);
        $spec = $this->sourceSpec($source);
        if ($spec === null) {
            return null;
        }
        [$table, $typeColumn] = $spec;

        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName($table))
                ->where('id = ?', $id)
        );
        if (!is_array($row) || !$row) {
            return null;
        }

        return $this->normalize($row, $source, $typeColumn);
    }

    /**
     * The rows of one queue that are parked as failed, keyed by id and
     * narrowed to the columns a resend decision reads. The mass retry asks
     * about a whole selection at once, where a payload per row would be
     * dead weight — and a row that never failed is not its business.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    public function loadFailed(string $source, array $ids): array
    {
        $spec = $this->sourceSpec($source);
        if (!$ids || $spec === null) {
            return [];
        }
        [$table, $typeColumn] = $spec;

        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName($table),
                    ['id', 'entity_id', 'status', 'last_response', 'type' => $typeColumn]
                )
                ->where('id IN (?)', array_map('intval', $ids))
                ->where('status = ?', Event::STATUS_FAILED)
        );

        return array_column($rows, null, 'id');
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function sourceSpec(string $source): ?array
    {
        return self::SOURCES[$source] ?? null;
    }

    /**
     * The row as the Log reads it. A withdrawn row is stored as sent with
     * the cancelled marker in place of an API reply (PRO-2453), a skipped
     * one as sent with its reason in last_error (PRO-3619); each reads as
     * its own status here, by the same rule the grid's UNION applies.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row, string $source, string $typeColumn): array
    {
        $row['source'] = $source;
        $row['type'] = (string)($row[$typeColumn] ?? '');
        if ($source !== Collection::SOURCE_SMAILY || (string)($row['status'] ?? '') !== Event::STATUS_SENT) {
            return $row;
        }
        if ((string)($row['last_response'] ?? '') === EventQueue::CANCELLED_RESPONSE) {
            $row['status'] = Collection::STATUS_WITHDRAWN;
        } elseif ((string)($row['last_error'] ?? '') !== '') {
            $row['status'] = Collection::STATUS_SKIPPED;
        }

        return $row;
    }
}
