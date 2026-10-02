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
        $event = $this->eventFactory->create();
        $event->addData([
            'domain' => $domain,
            'entity_id' => $entityId,
            'event_uuid' => $eventUuid ?? $this->identityGenerator->generateId(),
            'store_id' => $storeId,
            'payload' => $this->serializer->serialize($payload),
            'status' => IngestEvent::STATUS_PENDING,
            'attempts' => 0,
        ]);

        try {
            $this->eventResource->save($event);
        } catch (AlreadyExistsException) {
            return false;
        }

        return true;
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
        $table = $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME);
        $connection->update(
            $table,
            [
                'status' => IngestEvent::STATUS_SENDING,
                'claim_token' => $token,
                'claimed_at' => $now,
            ],
            [
                'id IN (?)' => array_keys($events),
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

        $ids = array_map(static fn (IngestEvent $event): int => (int)$event->getId(), $events);

        $connection = $this->resourceConnection->getConnection();
        $connection->update(
            $this->resourceConnection->getTableName(IngestEventResource::TABLE_NAME),
            ['status' => IngestEvent::STATUS_PENDING, 'claim_token' => null],
            ['id IN (?)' => $ids]
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

    private function nextRetryAt(int $attempts): string
    {
        $backoff = self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $backoff);
    }
}
