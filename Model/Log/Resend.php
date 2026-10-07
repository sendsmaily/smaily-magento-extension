<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * The Log's "Send again" action (PRO-2454): queue a fresh attempt of a row
 * that failed.
 *
 * The failed row is never touched — it is the history of what went wrong,
 * and the new row is the audit record of the decision to try again (the
 * same rule the Woo plugin settled on). The record rides inside the new
 * row's stored payload under a reserved key, so it needs no column of its
 * own; both queues strip it back out at flush time, so nothing of it ever
 * reaches Smaily or the engine.
 */
class Resend
{
    /** The reserved payload key holding {of, by, at}. */
    public const PAYLOAD_KEY = '_resend';

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly IngestQueue $ingestQueue,
        private readonly PayloadDecoder $payloadDecoder
    ) {
    }

    /**
     * Queue one row again. False when the queue refused the insert.
     *
     * @param array<string, mixed> $row raw queue row, as QueueRowLoader reads it
     */
    public function resend(array $row, string $user): bool
    {
        $payload = $this->payloadDecoder->decode((string)($row['payload'] ?? ''));
        $payload[self::PAYLOAD_KEY] = [
            'of' => (int)$row['id'],
            'by' => $user,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $entityId = isset($row['entity_id']) ? (string)$row['entity_id'] : null;

        if ((string)$row['source'] === Collection::SOURCE_SMAILY) {
            return $this->eventQueue->enqueue(
                (string)$row['event_type'],
                $payload,
                $entityId,
                (int)($row['website_id'] ?? 0)
            );
        }

        return $this->ingestQueue->enqueue(
            (string)$row['domain'],
            $payload,
            $entityId,
            isset($row['store_id']) ? (int)$row['store_id'] : null
        );
    }

    /**
     * The resend record a decoded payload carries, or null for a row that
     * was queued by the store itself. Stored Z-suffixed; handed over as the
     * plain UTC string the drawer's other dates are, so all of them read in
     * the admin's own timezone.
     *
     * @param array<int|string, mixed> $payload
     * @return array{of: int, by: string, at: string}|null
     */
    public function recordOf(array $payload): ?array
    {
        $record = $payload[self::PAYLOAD_KEY] ?? null;
        if (!is_array($record)) {
            return null;
        }

        $record['of'] = (int)($record['of'] ?? 0);
        $record['by'] = (string)($record['by'] ?? '');
        $record['at'] = gmdate('Y-m-d H:i:s', (int)strtotime((string)($record['at'] ?? '')));

        return $record;
    }

    /**
     * The payload as it goes on the wire: the "Send again" record is our own
     * bookkeeping (PRO-2454), stored with the row and never sent. Static so
     * both queues can strip it without depending on this class.
     *
     * @param array<int|string, mixed> $payload
     * @return array<int|string, mixed>
     */
    public static function stripRecord(array $payload): array
    {
        unset($payload[self::PAYLOAD_KEY]);

        return $payload;
    }
}
