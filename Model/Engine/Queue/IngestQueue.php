<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\Queue\RowWriter;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent\CollectionFactory;

/**
 * Durable engine ingest queue with the shared cross-platform retry policy:
 * backoff 60s/5m/15m/1h/6h, max 5 attempts (contract queue semantics; same
 * numbers as the Woo IngestQueue and the Shopify IngestQueueRow).
 *
 * Rows store the final wire payload; event_uuid doubles as the wire
 * event_id, so engine-side transport dedup makes retries safe.
 *
 * A row's store_id names the Campaign Intelligence connection it goes to
 * (CON-237): the connection's canonical store view
 * (Engine\Connection::canonicalStoreId()) — every enqueue site passes it
 * — and NULL for the primary (every row queued before CON-237).
 * claimBatch(), latestUndeliveredPayloads() and hasRow() read one
 * connection's rows when they are handed a connection (applyConnection()).
 */
class IngestQueue
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    /**
     * The columns markFailedMany() writes: a failure's outcome and its
     * exchange, plus the released claim and the outcome's time
     * (outcomeFields()) — what the model's own save in markFailed() writes
     * there too, so a row failed in a batch ends exactly like one failed
     * alone.
     */
    private const FAILURE_COLUMNS = [
        'attempts',
        'status',
        'next_retry_at',
        'last_error',
        'sent_payload',
        'last_response',
        'claim_token',
        'claimed_at',
        'updated_at',
    ];

    public function __construct(
        private readonly IngestEventFactory $eventFactory,
        private readonly IngestEventResource $eventResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Json $serializer,
        private readonly PayloadDecoder $payloadDecoder,
        private readonly DateTime $dateTime,
        private readonly ResourceConnection $resourceConnection,
        private readonly Logger $logger,
        private readonly RowWriter $rowWriter
    ) {
    }

    /**
     * Queue one wire item for a domain. Payload is stored as the final wire
     * object; event_id is filled from the row UUID at flush time. An
     * import's row carries its job's id, so the send run can count the rows
     * the engine accepted for that import (CON-241).
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $domain,
        array $payload,
        ?string $entityId = null,
        ?int $storeId = null,
        ?string $eventUuid = null,
        ?int $jobId = null
    ): bool {
        return $this->enqueueReturningId($domain, $payload, $entityId, $storeId, $eventUuid, $jobId) !== null;
    }

    /**
     * enqueue(), answering the new row's id (null when not queued), for a
     * caller that may hand the row a newer payload later in the request
     * (replacePendingPayload()).
     *
     * @param array<string, mixed> $payload
     */
    public function enqueueReturningId(
        string $domain,
        array $payload,
        ?string $entityId = null,
        ?int $storeId = null,
        ?string $eventUuid = null,
        ?int $jobId = null
    ): ?int {
        $event = $this->eventFactory->create();
        $event->addData($this->row($domain, $payload, $entityId, $storeId, $eventUuid, $jobId));

        try {
            $this->eventResource->save($event);
        } catch (AlreadyExistsException) {
            return null;
        }

        return (int)$event->getId() ?: null;
    }

    /**
     * A row that is not saved yet, for a send made outside the queue that
     * the Log still records (the nightly catalog manifest, PRO-3854): the
     * caller records the exchange on it, and markSent() or markFailed()
     * saves it with the outcome, so no flusher ever sees it pending.
     * $storeId names the connection it was sent to, as enqueue()'s does.
     *
     * @param array<string, mixed> $payload
     */
    public function newEvent(string $domain, array $payload, ?int $storeId = null): IngestEvent
    {
        $event = $this->eventFactory->create();
        $event->addData($this->row($domain, $payload, null, $storeId));

        return $event;
    }

    /**
     * Put a newer payload into a row queued earlier, instead of queuing a
     * second row, while that row is still pending and was never tried: it
     * has not reached the engine, so the engine gets only the newer payload
     * (PRO-1955: one refund, one order row). False when the row is claimed,
     * tried or gone — the caller queues a row of its own then.
     *
     * @param array<string, mixed> $payload
     */
    public function replacePendingPayload(int $id, array $payload): bool
    {
        return $this->resourceConnection->getConnection()->update(
            $this->table(),
            ['payload' => $this->serializer->serialize($payload)],
            [
                'id = ?' => $id,
                'status = ?' => IngestEvent::STATUS_PENDING,
                'attempts = ?' => 0,
            ]
        ) > 0;
    }

    /**
     * Queue several rows for one domain with a single INSERT (PRO-1967: a
     * bulk stock write must not turn into one round trip per product). Each
     * row gets its own uuid, as enqueue() gives it.
     *
     * @param array<int, array{payload: array<string, mixed>, entity_id: ?string, store_id: ?int}> $rows
     * @return int the number of rows queued
     */
    public function enqueueMany(string $domain, array $rows): int
    {
        if (!$rows) {
            return 0;
        }

        $data = [];
        foreach ($rows as $row) {
            $data[] = $this->row($domain, $row['payload'], $row['entity_id'], $row['store_id']);
        }

        return $this->resourceConnection->getConnection()->insertMultiple($this->table(), $data);
    }

    /**
     * Queue each entity's payload unless it is exactly the entity's newest
     * row that is not delivered yet — compared as the queue stores it, so
     * one change that reaches several hooks queues one row, not two
     * (PRO-1967). Not a queue-wide dedupe: an older unsent row, or one
     * already delivered, does not stop a new row.
     *
     * The rows are the connection's: compared with its own rows only, and
     * stored with its canonical store view (CON-237).
     *
     * @param array<int|string, array<string, mixed>> $payloads entity id => payload
     * @return int the number of rows queued
     */
    public function enqueueChangedPayloads(string $domain, array $payloads, Connection $connection): int
    {
        $storeId = $connection->canonicalStoreId();
        $latest = $this->latestUndeliveredPayloads(
            $domain,
            array_map('strval', array_keys($payloads)),
            $connection
        );

        $rows = [];
        foreach ($payloads as $entityId => $payload) {
            $entityId = (string)$entityId;
            if (isset($latest[$entityId]) && $latest[$entityId] === $this->serializer->serialize($payload)) {
                continue;
            }
            $rows[] = ['payload' => $payload, 'entity_id' => $entityId, 'store_id' => $storeId];
        }

        return $this->enqueueMany($domain, $rows);
    }

    /**
     * The stored payload of each entity's newest row that is not delivered
     * yet (pending or being sent) — one row per entity is read. Reads only
     * undelivered rows, so it stays on the domain/status index however many
     * delivered rows are retained. With $engineConnection, only that
     * connection's rows.
     *
     * @param string[] $entityIds
     * @return array<string, string> entity id => payload as stored
     */
    public function latestUndeliveredPayloads(
        string $domain,
        array $entityIds,
        ?Connection $engineConnection = null
    ): array {
        if (!$entityIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $newest = $connection->select()
            ->from($this->table(), ['id' => 'MAX(id)'])
            ->where('domain = ?', $domain)
            ->where('status IN (?)', [IngestEvent::STATUS_PENDING, IngestEvent::STATUS_SENDING])
            ->where('entity_id IN (?)', $entityIds)
            ->group('entity_id');
        if (!$this->applyConnection($newest, $engineConnection)) {
            return [];
        }
        $select = $connection->select()
            ->from(['queue' => $this->table()], ['entity_id', 'payload'])
            ->join(['newest' => $newest], 'newest.id = queue.id', []);

        $payloads = [];
        foreach ($connection->fetchAll($select) as $row) {
            $payloads[(string)$row['entity_id']] = (string)$row['payload'];
        }

        return $payloads;
    }

    /**
     * Whether the queue holds any row of $domain for the entity, in any
     * state: the store has queued it to be sent (CON-262: a customer the
     * live sync or an import sends). Read only after an identity merge met
     * `404 customer_not_found`, so it may walk the domain's rows. With
     * $engineConnection, only that connection's rows.
     */
    public function hasRow(string $domain, string $entityId, ?Connection $engineConnection = null): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['id'])
            ->where('domain = ?', $domain)
            ->where('entity_id = ?', $entityId)
            ->limit(1);
        if (!$this->applyConnection($select, $engineConnection)) {
            return false;
        }

        return $connection->fetchOne($select) !== false;
    }

    /**
     * Remove rows that have done their job without being sent.
     *
     * @param IngestEvent[] $events
     */
    public function delete(array $events): void
    {
        if (!$events) {
            return;
        }

        $this->resourceConnection->getConnection()->delete($this->table(), ['id IN (?)' => $this->ids($events)]);
    }

    /**
     * Claim due pending events for one domain (marks them as sending). With
     * $engineConnection, only that connection's rows (CON-237); without
     * one, every row of the domain — the `catalog_changed` markers, which
     * belong to no connection.
     *
     * @return IngestEvent[]
     */
    public function claimBatch(string $domain, int $limit, ?Connection $engineConnection = null): array
    {
        $now = $this->dateTime->gmtDate();
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('domain', $domain)
            ->addFieldToFilter('status', IngestEvent::STATUS_PENDING)
            ->addFieldToFilter('next_retry_at', [
                ['null' => true],
                ['lteq' => $now],
            ])
            ->setOrder('id', 'ASC')
            ->setPageSize($limit);
        if (!$this->applyConnection($collection->getSelect(), $engineConnection)) {
            return [];
        }

        $events = [];
        foreach ($collection->getItems() as $item) {
            if ($item instanceof IngestEvent) {
                $events[(int)$item->getId()] = $item;
            }
        }
        if (!$events) {
            return [];
        }

        // Claim with a per-worker token so concurrent flushes never
        // double-send (see EventQueue::claimBatch for the rationale).
        $token = $this->identityGenerator->generateId();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->table();
        $connection->update(
            $table,
            [
                'status' => IngestEvent::STATUS_SENDING,
                'claim_token' => $token,
                'claimed_at' => $now,
            ],
            [
                'id IN (?)' => $this->ids($events),
                'status = ?' => IngestEvent::STATUS_PENDING,
            ]
        );

        $claimedIds = array_map('intval', $connection->fetchCol(
            $connection->select()->from($table, ['id'])
                ->where('claim_token = ?', $token)
                ->where('status = ?', IngestEvent::STATUS_SENDING)
        ));

        $claimed = [];
        foreach ($claimedIds as $id) {
            if (isset($events[$id])) {
                $events[$id]->setData('status', IngestEvent::STATUS_SENDING);
                $claimed[] = $events[$id];
            }
        }

        return $claimed;
    }

    /**
     * Return rows stuck in "sending" (killed worker) back to pending.
     */
    public function requeueStale(int $olderThanSeconds = 900): int
    {
        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME),
            ['status' => IngestEvent::STATUS_PENDING, 'claim_token' => null],
            [
                'status = ?' => IngestEvent::STATUS_SENDING,
                'claimed_at < ?' => $this->dateTime->gmtDate(
                    'Y-m-d H:i:s',
                    $this->dateTime->gmtTimestamp() - $olderThanSeconds
                ),
            ]
        );
    }

    /**
     * Hand claimed rows straight back to pending — attempts, backoff and
     * error untouched. Used when the batch was never at fault: a refused
     * account (contract §2 `403 tenant_inactive`) stops sending until the
     * account is live again, and its rows wait in line for that (PRO-2451).
     *
     * @param IngestEvent[] $events
     */
    public function release(array $events): void
    {
        if (!$events) {
            return;
        }

        $this->resourceConnection->getConnection()->update(
            $this->table(),
            ['status' => IngestEvent::STATUS_PENDING, 'claim_token' => null],
            ['id IN (?)' => $this->ids($events)]
        );
        foreach ($events as $event) {
            $event->setData('status', IngestEvent::STATUS_PENDING);
        }
    }

    /**
     * Remember on the row what this attempt put on the wire and what came
     * back, for the Log's Details. Nothing is saved here: markSent() or
     * markFailed() stores it with the attempt's outcome. A null $response
     * (no answer arrived) keeps the last answer the row did get (PRO-1963).
     *
     * @param array<int|string, mixed> $sentPayload this row's part of the request body
     * @param array<string, mixed>|null $response
     */
    public function recordExchange(IngestEvent $event, array $sentPayload, ?array $response): void
    {
        $event->setData('sent_payload', $this->serializer->serialize($sentPayload));
        if ($response !== null) {
            $event->setData('last_response', $this->serializer->serialize($response));
        }
    }

    /**
     * $response replaces the reply the row holds; without one, the reply
     * recordExchange() kept stays.
     */
    public function markSent(IngestEvent $event, ?string $response = null): void
    {
        $event->addData([
            'status' => IngestEvent::STATUS_SENT,
            'last_error' => null,
            'last_response' => $response ?? $event->getData('last_response'),
        ] + $this->outcomeFields());
        $this->eventResource->save($event);
    }

    public function markFailed(IngestEvent $event, string $error, bool $terminal = false): void
    {
        $exhausted = $this->applyFailure($event, $error, $terminal);
        $this->eventResource->save($event);

        if ($exhausted) {
            $this->logParked([$event], $error);
        }
    }

    /**
     * Record one failure that many rows share — the engine's one answer to
     * a batch, or no answer at all (PRO-3962, as EventQueue::markFailedMany()
     * for the marketing queue): each row ends exactly as markFailed() leaves
     * it, but in one UPDATE per RowWriter::CHUNK rows, and the rows it parks
     * get one error-log line with their count instead of a line each. Each
     * row keeps its own step on the retry ladder (attempts, status, next
     * retry follow its own attempt count) and the exchange recorded on it.
     * A single row goes through markFailed().
     *
     * @param IngestEvent[] $events
     */
    public function markFailedMany(array $events, string $error, bool $terminal = false): void
    {
        if (count($events) < 2) {
            foreach ($events as $event) {
                $this->markFailed($event, $error, $terminal);
            }

            return;
        }

        $parked = [];
        foreach ($events as $event) {
            if ($this->applyFailure($event, $error, $terminal)) {
                $parked[] = $event;
            }
        }
        $this->rowWriter->write(IngestEventResource::TABLE_NAME, $events, self::FAILURE_COLUMNS);

        if ($parked) {
            $this->logParked($parked, $error);
        }
    }

    /**
     * Reset failed events back to pending (admin manual retry).
     *
     * A row anonymised by an Art. 17 erasure (PRO-2452) is skipped: it no
     * longer holds a recipient, so retrying it would put the placeholder on
     * the wire.
     *
     * @param int[] $ids
     */
    public function retry(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME),
            [
                'status' => IngestEvent::STATUS_PENDING,
                'attempts' => 0,
                'next_retry_at' => null,
            ],
            [
                'id IN (?)' => array_map('intval', $ids),
                'status = ?' => IngestEvent::STATUS_FAILED,
                'entity_id IS NULL OR entity_id != ?' => Erasure::PLACEHOLDER,
            ]
        );
    }

    /**
     * Number of undelivered rows for a domain (backfill flood guard).
     *
     * @phpstan-impure
     */
    public function countPending(string $domain): int
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME), ['cnt' => 'COUNT(*)'])
            ->where('domain = ?', $domain)
            ->where('status IN (?)', [IngestEvent::STATUS_PENDING, IngestEvent::STATUS_SENDING]);

        return (int)$connection->fetchOne($select);
    }

    /**
     * Number of these import jobs' rows not delivered yet (pending, also
     * waiting for a retry, or being sent) — with $upToId, only the rows up
     * to that queue row id. A row the engine refused for good counts as
     * delivered here: no later run sends it.
     *
     * @param int[] $jobIds
     * @phpstan-impure
     */
    public function countUndelivered(array $jobIds, ?int $upToId = null): int
    {
        if (!$jobIds) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->table(), ['cnt' => 'COUNT(*)'])
            ->where('job_id IN (?)', $jobIds)
            ->where('status IN (?)', [IngestEvent::STATUS_PENDING, IngestEvent::STATUS_SENDING]);
        if ($upToId !== null) {
            $select->where('id <= ?', $upToId);
        }

        return (int)$connection->fetchOne($select);
    }

    /**
     * The id of the import job's newest row, 0 when it queued none.
     *
     * @phpstan-impure
     */
    public function lastRowId(int $jobId): int
    {
        $connection = $this->resourceConnection->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()->from($this->table(), ['last' => 'MAX(id)'])->where('job_id = ?', $jobId)
        );
    }

    /**
     * The stored payload of the import job's newest delivered row, null
     * while none is delivered. An orders import queues newest first, so
     * this is the oldest order it has sent so far.
     *
     * @return array<int|string, mixed>|null
     * @phpstan-impure
     */
    public function lastSentPayload(int $jobId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $payload = $connection->fetchOne(
            $connection->select()->from($this->table(), ['payload'])
                ->where('job_id = ?', $jobId)
                ->where('status = ?', IngestEvent::STATUS_SENT)
                ->order('id DESC')
                ->limit(1)
        );

        return is_string($payload) && $payload !== '' ? $this->payloadDecoder->decode($payload) : null;
    }

    /**
     * Decode a row payload and stamp the wire event_id from the row UUID.
     *
     * @return array<string, mixed>
     */
    public function decodePayload(IngestEvent $event): array
    {
        $payload = Resend::stripRecord($this->payloadDecoder->decode($event->getPayload()));
        $payload['event_id'] = $event->getEventUuid();

        return $payload;
    }

    /**
     * Narrow a select to one connection's rows: the rows that carry one of
     * its store views, and for the primary also the rows that carry none
     * (NULL — every row queued before CON-237). A connection that serves
     * every website is not narrowed: no other connection serves a store
     * view, so every row is its — a row of a store view deleted since it
     * was queued too — and one store reads exactly the rows it always
     * read. False when the connection can have no rows (it serves no
     * website and is not the primary).
     */
    private function applyConnection(Select $select, ?Connection $engineConnection): bool
    {
        if ($engineConnection === null || $engineConnection->servesAllWebsites()) {
            return true;
        }
        $storeIds = $engineConnection->storeIds();
        if (!$engineConnection->isPrimary()) {
            if (!$storeIds) {
                return false;
            }
            $select->where('store_id IN (?)', $storeIds);

            return true;
        }
        if ($storeIds) {
            $select->where('(store_id IS NULL OR store_id IN (?))', $storeIds);
        } else {
            $select->where('store_id IS NULL');
        }

        return true;
    }

    /**
     * The row enqueue() saves and enqueueMany() inserts.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function row(
        string $domain,
        array $payload,
        ?string $entityId,
        ?int $storeId,
        ?string $eventUuid = null,
        ?int $jobId = null
    ): array {
        return [
            'domain' => $domain,
            'entity_id' => $entityId,
            'event_uuid' => $eventUuid ?? $this->identityGenerator->generateId(),
            'store_id' => $storeId,
            'payload' => $this->serializer->serialize($payload),
            'status' => IngestEvent::STATUS_PENDING,
            'attempts' => 0,
            'job_id' => $jobId,
        ];
    }

    /**
     * Set one failed attempt's outcome on the row model, unsaved.
     *
     * @return bool whether the row is parked as failed
     */
    private function applyFailure(IngestEvent $event, string $error, bool $terminal): bool
    {
        $attempts = $event->getAttempts() + 1;
        $exhausted = $terminal || $attempts >= self::MAX_ATTEMPTS;

        $event->addData([
            'attempts' => $attempts,
            'status' => $exhausted ? IngestEvent::STATUS_FAILED : IngestEvent::STATUS_PENDING,
            'next_retry_at' => $exhausted ? null : $this->nextRetryAt($attempts),
            'last_error' => mb_substr($error, 0, 60000),
        ] + $this->outcomeFields());

        return $exhausted;
    }

    /**
     * One error-log line for the rows one failure parks — a single row's
     * line has the same wording and fields as a group's (PRO-3962).
     *
     * @param IngestEvent[] $parked
     */
    private function logParked(array $parked, string $error): void
    {
        $this->logger->error('Ingest events failed permanently', [
            'count' => count($parked),
            'domain' => implode(', ', array_unique(array_map(
                static fn (IngestEvent $event): string => $event->getDomain(),
                $parked
            ))),
            'ids' => $this->ids($parked),
            'error' => $error,
        ]);
    }

    /**
     * @param IngestEvent[] $events
     * @return int[]
     */
    private function ids(array $events): array
    {
        return array_map(static fn (IngestEvent $event): int => (int)$event->getId(), array_values($events));
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME);
    }

    /**
     * What every outcome of a row writes besides its own fields: the claim
     * released, and the row's last-changed time set to the outcome's
     * (PRO-3961), as EventQueue::outcomeFields() does — a claimed row model
     * holds all three as they were before the claim.
     *
     * @return array{claim_token: null, claimed_at: null, updated_at: string}
     */
    private function outcomeFields(): array
    {
        return [
            'claim_token' => null,
            'claimed_at' => null,
            'updated_at' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
        ];
    }

    private function nextRetryAt(int $attempts): string
    {
        $backoff = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $backoff);
    }
}
