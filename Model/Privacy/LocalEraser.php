<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Cron\QueueJanitor;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * The local half of a data-subject request (PRO-2452): the module's OWN
 * tables that can still hold a contact's address after the engine has been
 * asked to forget them — both delivery queues and the abandoned-cart side
 * table. Until this existed, those rows only cleared when the janitor's
 * retention window came round, 30 or 90 days later.
 *
 * Two outcomes, per Erkki's binding decision (2026-09-10), mirroring the
 * WooCommerce sibling's PRO-2383:
 *
 * - **A row that could still send is DELETED.** Not sending is the point of
 *   the erasure, so nothing survives that a flusher could still put on the
 *   wire — `pending` and `sending` alike.
 * - **A row that is over is ANONYMISED in place.** Deleting it would erase
 *   the merchant's own record that they messaged this person. The row keeps
 *   its id, type, status, attempts and timestamps and loses everything that
 *   points at the person: `entity_id` (which for a contact.sync or an
 *   automation row IS the address — its keyed hash when the address is
 *   longer than the column — for a profiling-consent row the opt-out
 *   record's keyed hash of it, and is the Log grid's Entity column),
 *   the queued payload, the payload as sent, the last response and the last
 *   error.
 * - **An abandoned-cart row is ALWAYS anonymised**, whatever its status
 *   (PRO-2467). It is not a message; it is the marker saying this quote has
 *   been handled, and the module may not touch the core `quote` table. Take
 *   the row away and a still-active idle quote is picked up by the next
 *   sweep and mailed to the address just erased, so what stays behind is an
 *   email-less tombstone with status `erased`. An active cart that holds the
 *   address on the cart or on one of its addresses, but has no such row yet,
 *   gets one too (PRO-3693): the scan would otherwise mail it on its next
 *   sweep. The core quote rows are read, never changed.
 *
 * Rows are found by decoding, never by searching the raw JSON text — see
 * PayloadAnonymizer. Erasure is idempotent by construction: an anonymised
 * row no longer carries the address it was matched on.
 *
 * Results are keyed by a merchant-facing label, never by a table name: the
 * CLI prints them as they come.
 */
class LocalEraser
{
    /**
     * The column each queue table calls its event type. Which tables those
     * are is the janitor's list — the same pair both sweeps walk.
     */
    private const TYPE_COLUMNS = [
        EventResource::TABLE_NAME => 'event_type',
        IngestEventResource::TABLE_NAME => 'domain',
    ];

    /**
     * What the merchant sees a queue called.
     */
    private const LABELS = [
        EventResource::TABLE_NAME => 'Queued messages',
        IngestEventResource::TABLE_NAME => 'Engine queue',
    ];

    private const ABANDONED_CART_LABEL = 'Abandoned carts';

    /**
     * Statuses a row can still be sent from. Both queues use the same
     * vocabulary. Naming the sendable set beats negating `sent`: a status
     * added later has to be placed on one side or the other on purpose.
     */
    private const SENDABLE_STATUSES = [Event::STATUS_PENDING, Event::STATUS_SENDING];

    /**
     * Rows read per scan pass. The blobs are mediumtext, so the scan is
     * chunked rather than loaded whole, and each chunk is dealt with before
     * the next is read.
     */
    private const SCAN_CHUNK = 1000;

    /**
     * The blob columns a queue row can hide an address in.
     */
    private const MATCHED_COLUMNS = ['payload', 'sent_payload', 'last_response', 'last_error'];

    /**
     * What the scan reads: the blobs it matches on, what the erasure needs
     * to decide the row's fate and what the export prints. Never `SELECT *`
     * — the rest of a queue row is of no use here.
     */
    private const SCANNED_COLUMNS = ['id', 'status', 'entity_id', 'created_at', ...self::MATCHED_COLUMNS];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly PayloadAnonymizer $anonymizer,
        private readonly StateManager $cartState,
        private readonly ContactEntity $contactEntity
    ) {
    }

    /**
     * Erase everything local for a contact.
     *
     * @return array<string, array{removed: int, anonymised: int}> keyed by label
     */
    public function erase(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $counts = [];
        foreach (QueueJanitor::TABLES as $table) {
            $counts[self::LABELS[$table]] = $this->eraseQueue($table, $email);
        }
        $counts[self::ABANDONED_CART_LABEL] = [
            'removed' => 0,
            'anonymised' => $this->cartState->eraseForEmail($email),
        ];

        return $counts;
    }

    /**
     * The same rows the erasure would touch, for the Art. 15 export: what
     * the store queued for this address and when, never the message body
     * (which is built from data Magento and Smaily already export).
     *
     * @return array<string, array<int, array<string, mixed>>> keyed by label
     */
    public function export(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }

        $rows = [];
        foreach (QueueJanitor::TABLES as $table) {
            $typeColumn = self::TYPE_COLUMNS[$table];
            $exported = [];
            foreach ($this->scan($table, $email) as $matched) {
                foreach ($matched as [$row]) {
                    $exported[] = [
                        'type' => (string)$row[$typeColumn],
                        'status' => (string)$row['status'],
                        'created_at' => (string)$row['created_at'],
                    ];
                }
            }
            $rows[self::LABELS[$table]] = $exported;
        }
        $rows[self::ABANDONED_CART_LABEL] = $this->cartState->rowsForEmail($email);

        return $rows;
    }

    /**
     * @return array{removed: int, anonymised: int}
     */
    private function eraseQueue(string $table, string $email): array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);

        $result = ['removed' => 0, 'anonymised' => 0];
        foreach ($this->scan($table, $email) as $matched) {
            $sendableIds = [];
            $connection->beginTransaction();
            try {
                foreach ($matched as [$row, $decoded]) {
                    if (in_array((string)$row['status'], self::SENDABLE_STATUSES, true)) {
                        $sendableIds[] = (int)$row['id'];
                        continue;
                    }
                    $connection->update(
                        $tableName,
                        $this->anonymisedValues($row, $decoded),
                        ['id = ?' => (int)$row['id']]
                    );
                    $result['anonymised']++;
                }
                if ($sendableIds) {
                    $result['removed'] += $connection->delete($tableName, ['id IN (?)' => $sendableIds]);
                }
                $connection->commit();
            } catch (\Throwable $exception) {
                $connection->rollBack();
                throw $exception;
            }
        }

        return $result;
    }

    /**
     * What a kept row carries afterwards.
     *
     * `updated_at` is written as itself (PRO-3963): the column moves on
     * every UPDATE unless it is set explicitly, and it is the row's
     * last-outcome time that the janitor counts retention from. Anonymising
     * is not an outcome, so the row keeps its 30- or 90-day schedule.
     *
     * @param array<string, mixed> $row
     * @param array<string, array<int|string, mixed>|null> $decoded
     * @return array<string, string|null|\Zend_Db_Expr>
     */
    private function anonymisedValues(array $row, array $decoded): array
    {
        $values = [
            'entity_id' => Erasure::PLACEHOLDER,
            'updated_at' => new \Zend_Db_Expr('updated_at'),
        ];
        foreach (self::MATCHED_COLUMNS as $column) {
            $stored = $this->column($row, $column);
            // last_error is free text, not a payload: there is no structure
            // worth keeping, so it goes wholesale.
            $values[$column] = $column === 'last_error'
                ? ($stored === null ? null : Erasure::PLACEHOLDER)
                : $this->anonymizer->anonymize($stored, $decoded[$column]);
        }

        return $values;
    }

    /**
     * Walk a queue table in id chunks, yielding the rows of each chunk that
     * mention this contact together with their decoded blobs.
     *
     * The walk is a full table scan: neither queue carries a recipient
     * column to index (a contact.sync row's entity_id happens to be the
     * address, an ingest row's is a product or customer id), and the address
     * inside the JSON cannot be matched in SQL without falling into the
     * escaping trap PayloadAnonymizer exists to avoid. An erasure is an
     * admin-triggered one-off where completeness beats speed. Yielding per
     * chunk keeps the caller's peak memory at one chunk, whatever the table
     * holds.
     *
     * @return \Generator<int, array<int, array{array<string, mixed>, array<string, ?array<int|string, mixed>>}>>
     */
    private function scan(string $table, string $email): \Generator
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName($table);
        $columns = array_merge(self::SCANNED_COLUMNS, [self::TYPE_COLUMNS[$table]]);
        // A profiling-consent row's entity is the keyed hash (PRO-3765), and
        // so is a contact-sync or automation row's for an address longer
        // than the column (PRO-3767); any other row's, the plain address.
        $entities = $this->contactEntity->forms($email);

        $lastId = 0;
        while (true) {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($tableName, $columns)
                    ->where('id > ?', $lastId)
                    ->order('id ASC')
                    ->limit(self::SCAN_CHUNK)
            );
            if (!$rows) {
                return;
            }
            $matched = [];
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $decoded = $this->matchRow($row, $email, $entities);
                if ($decoded !== null) {
                    $matched[] = [$row, $decoded];
                }
            }
            if ($matched) {
                yield $matched;
            }
        }
    }

    /**
     * The decoded blobs of a row that mentions this contact, or null when it
     * does not. A blob is decoded exactly once: the same decoding answers
     * the match and feeds the redaction.
     *
     * @param array<string, mixed> $row
     * @param string[] $entities the entity values that point at this contact
     * @return array<string, array<int|string, mixed>|null>|null
     */
    private function matchRow(array $row, string $email, array $entities): ?array
    {
        $matched = in_array(strtolower(trim((string)$this->column($row, 'entity_id'))), $entities, true);

        $decoded = [];
        foreach (self::MATCHED_COLUMNS as $column) {
            $stored = $this->column($row, $column);
            $decoded[$column] = $this->anonymizer->decode($stored);
            $matched = $matched || $this->anonymizer->matches($stored, $decoded[$column], $email);
        }

        return $matched ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function column(array $row, string $name): ?string
    {
        $value = $row[$name] ?? null;

        return $value === null ? null : (string)$value;
    }
}
