<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent\CollectionFactory;

/**
 * Durable engine ingest queue with the shared cross-platform retry policy:
 * backoff 60s/5m/15m/1h/6h, max 5 attempts (contract queue semantics; same
 * numbers as the Woo IngestQueue and the Shopify IngestQueueRow).
 *
 * Rows store the final wire payload; event_uuid doubles as the wire
 * event_id, so engine-side transport dedup makes retries safe.
 */
class IngestQueue
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];

    public function __construct(
        private readonly IngestEventFactory $eventFactory,
        private readonly IngestEventResource $eventResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly IdentityGeneratorInterface $identityGenerator,
        private readonly Json $serializer,
        private readonly PayloadDecoder $payloadDecoder,
        private readonly DateTime $dateTime,
        private readonly ResourceConnection $resourceConnection,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue one wire item for a domain. Payload is stored as the final wire
     * object; event_id is filled from the row UUID at flush time.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $domain,
        array $payload,
        ?string $entityId = null,
        ?int $storeId = null,
        ?string $eventUuid = null
    ): bool {
        return $this->enqueueReturningId($domain, $payload, $entityId, $storeId, $eventUuid) !== null;
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
        ?string $eventUuid = null
    ): ?int {
        $event = $this->eventFactory->create();
        $event->addData($this->row($domain, $payload, $entityId, $storeId, $eventUuid));

        try {
            $this->eventResource->save($event);
        } catch (AlreadyExistsException) {
            return null;
        }

        return (int)$event->getId() ?: null;
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
     * @param array<int|string, array<string, mixed>> $payloads entity id => payload
     * @return int the number of rows queued
     */
    public function enqueueChangedPayloads(string $domain, array $payloads, ?int $storeId): int
    {
        $latest = $this->latestUndeliveredPayloads($domain, array_map('strval', array_keys($payloads)));

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
     * delivered rows are retained.
     *
     * @param string[] $entityIds
     * @return array<string, string> entity id => payload as stored
     */
    public function latestUndeliveredPayloads(string $domain, array $entityIds): array
    {
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
     * Claim due pending events for one domain (marks them as sending).
     *
     * @return IngestEvent[]
     */
    public function claimBatch(string $domain, int $limit): array
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
        ]);
        $this->eventResource->save($event);
    }

    public function markFailed(IngestEvent $event, string $error, bool $terminal = false): void
    {
        $attempts = $event->getAttempts() + 1;
        $exhausted = $terminal || $attempts >= self::MAX_ATTEMPTS;

        $event->addData([
            'attempts' => $attempts,
            'status' => $exhausted ? IngestEvent::STATUS_FAILED : IngestEvent::STATUS_PENDING,
            'next_retry_at' => $exhausted ? null : $this->nextRetryAt($attempts),
            'last_error' => mb_substr($error, 0, 60000),
        ]);
        $this->eventResource->save($event);

        if ($exhausted) {
            $this->logger->error('Ingest event failed permanently', [
                'id' => $event->getId(),
                'domain' => $event->getDomain(),
                'error' => $error,
            ]);
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
        ?string $eventUuid = null
    ): array {
        return [
            'domain' => $domain,
            'entity_id' => $entityId,
            'event_uuid' => $eventUuid ?? $this->identityGenerator->generateId(),
            'store_id' => $storeId,
            'payload' => $this->serializer->serialize($payload),
            'status' => IngestEvent::STATUS_PENDING,
            'attempts' => 0,
        ];
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

    private function nextRetryAt(int $attempts): string
    {
        $backoff = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $backoff);
    }
}
