<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event\CollectionFactory;

/**
 * Durable, idempotent queue for outbound Smaily marketing events.
 *
 * Retry semantics mirror the WooCommerce plugin: exponential backoff of
 * 60s, 5m, 15m, 1h, 6h with at most 5 attempts, after which a row is
 * parked as failed for manual retry from the admin event log. Which
 * failures earn a retry at all is Model\Queue\Failure's call.
 */
class EventQueue
{
    public const MAX_ATTEMPTS = 5;
    public const BACKOFF_SECONDS = [60, 300, 900, 3600, 21600];
    public const MAX_ERROR_LENGTH = 60000;

    /**
     * What a withdrawn row records in place of an API reply. Not a status:
     * a status is what the flusher reads to decide what to send, and a
     * cancelled row is terminal exactly like a delivered one.
     */
    public const CANCELLED_RESPONSE = 'cancelled';

    public function __construct(
        private readonly EventFactory $eventFactory,
        private readonly EventResource $eventResource,
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
     * Add an event to the queue.
     *
     * Passing a deterministic $eventUuid makes the enqueue idempotent:
     * a duplicate is silently skipped.
     *
     * @param array<int|string, mixed> $payload
     * @return bool true when queued, false when skipped as a duplicate
     */
    public function enqueue(
        string $eventType,
        array $payload,
        ?string $entityId = null,
        int $websiteId = 0,
        ?string $eventUuid = null
    ): bool {
        return $this->insert($eventType, $payload, $entityId, $websiteId, $eventUuid, []);
    }

    /**
     * Add an event already closed as skipped, the shape markSkipped() gives
     * a row: the Log shows what would have been sent and why it was not, and
     * the flusher never claims it.
     *
     * @param array<int|string, mixed> $payload
     */
    public function enqueueSkipped(
        string $eventType,
        array $payload,
        string $reason,
        ?string $entityId = null,
        int $websiteId = 0
    ): bool {
        return $this->insert($eventType, $payload, $entityId, $websiteId, null, $this->terminalFields(null) + [
            'last_error' => mb_substr($reason, 0, self::MAX_ERROR_LENGTH),
        ]);
    }

    /**
     * @param array<int|string, mixed> $payload
     * @param array<string, mixed> $closedFields the fields of a row stored already closed
     * @return bool true when stored, false when skipped as a duplicate
     */
    private function insert(
        string $eventType,
        array $payload,
        ?string $entityId,
        int $websiteId,
        ?string $eventUuid,
        array $closedFields
    ): bool {
        $event = $this->eventFactory->create();
        $event->addData([
            'event_type' => $eventType,
            'entity_id' => $entityId,
            'event_uuid' => $eventUuid ?? $this->identityGenerator->generateId(),
            'website_id' => $websiteId,
            'payload' => $this->serializer->serialize($payload),
            'status' => Event::STATUS_PENDING,
            'attempts' => 0,
        ]);
        if ($closedFields !== []) {
            $event->addData($closedFields);
        }

        try {
            $this->eventResource->save($event);
        } catch (AlreadyExistsException) {
            $this->logger->debug('Skipped duplicate queue event', [
                'event_type' => $eventType,
                'event_uuid' => $eventUuid,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Claim due pending events for processing (marks them as sending). The
     * rows of $exceptTypes are left alone: their handler cannot send now
     * (PRO-2466).
     *
     * @param string[] $exceptTypes
     * @return Event[]
     */
    public function claimBatch(int $limit = 200, array $exceptTypes = []): array
    {
        $now = $this->dateTime->gmtDate();
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', Event::STATUS_PENDING)
            ->addFieldToFilter('next_retry_at', [
                ['null' => true],
                ['lteq' => $now],
            ])
            ->setOrder('id', 'ASC')
            ->setPageSize($limit);
        if ($exceptTypes !== []) {
            $collection->addFieldToFilter('event_type', ['nin' => $exceptTypes]);
        }

        $events = [];
        foreach ($collection->getItems() as $item) {
            if ($item instanceof Event) {
                $events[(int)$item->getId()] = $item;
            }
        }
        if (!$events) {
            return [];
        }

        // Claim with a per-worker token: only rows this worker actually
        // transitioned are processed, so a concurrent flush (manual cron run,
        // multi-node cron) can never double-send the same event.
        $token = $this->identityGenerator->generateId();
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(EventResource::TABLE_NAME);
        $connection->update(
            $table,
            [
                'status' => Event::STATUS_SENDING,
                'claim_token' => $token,
                'claimed_at' => $now,
            ],
            [
                'id IN (?)' => array_keys($events),
                'status = ?' => Event::STATUS_PENDING,
            ]
        );

        $claimedIds = array_map('intval', $connection->fetchCol(
            $connection->select()->from($table, ['id'])
                ->where('claim_token = ?', $token)
                ->where('status = ?', Event::STATUS_SENDING)
        ));

        $claimed = [];
        foreach ($claimedIds as $id) {
            if (isset($events[$id])) {
                $events[$id]->setData('status', Event::STATUS_SENDING);
                $claimed[] = $events[$id];
            }
        }

        return $claimed;
    }

    /**
     * Return rows stuck in "sending" (killed worker, OOM, deploy) back to
     * pending so they are retried instead of being lost forever.
     */
    public function requeueStale(int $olderThanSeconds = 900): int
    {
        return $this->unclaim([
            'status = ?' => Event::STATUS_SENDING,
            'claimed_at < ?' => $this->dateTime->gmtDate(
                'Y-m-d H:i:s',
                $this->dateTime->gmtTimestamp() - $olderThanSeconds
            ),
        ]);
    }

    /**
     * Give claimed rows back as they were (PRO-2466): pending, unclaimed, no
     * attempt spent — the handler may not send them now through no fault of
     * theirs. What the handler set on the model is not saved.
     *
     * @param Event[] $events
     */
    public function release(array $events): void
    {
        if (!$events) {
            return;
        }

        $this->unclaim(['id IN (?)' => array_map(static fn (Event $event): int => (int)$event->getId(), $events)]);
        foreach ($events as $event) {
            $event->setData('status', Event::STATUS_PENDING);
        }
    }

    /**
     * Turn the rows $where names pending and unclaimed again.
     *
     * @param array<string, mixed> $where
     * @return int rows changed
     */
    private function unclaim(array $where): int
    {
        return $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(EventResource::TABLE_NAME),
            ['status' => Event::STATUS_PENDING, 'claim_token' => null],
            $where
        );
    }

    /**
     * Remember on the row what this attempt put on the wire and what came
     * back, for the Log's Details. Nothing is saved here: markSent() or
     * markFailed() stores it with the attempt's outcome. A null $response
     * (no answer arrived) keeps the last answer the row did get.
     *
     * @param array<int|string, mixed> $sentPayload this row's part of the request body
     * @param array<string, mixed>|null $response
     */
    public function recordExchange(Event $event, array $sentPayload, ?array $response): void
    {
        $event->setData('sent_payload', $this->serializer->serialize($sentPayload));
        if ($response !== null) {
            $event->setData('last_response', $this->serializer->serialize($response));
        }
    }

    /**
     * Mark an event as delivered.
     */
    public function markSent(Event $event, ?string $sentPayload = null, ?string $response = null): void
    {
        $event->addData(array_merge(
            $this->terminalFields($response),
            ['last_error' => null],
            $this->exchangeFields($event, $sentPayload, $response)
        ));
        $this->eventResource->save($event);
    }

    /**
     * Close an event for good without sending it (PRO-3619): nothing left to
     * retry, and the reason kept where the Log shows an error.
     */
    public function markSkipped(Event $event, string $reason): void
    {
        $event->addData(array_merge(
            $this->terminalFields(null),
            ['last_error' => mb_substr($reason, 0, self::MAX_ERROR_LENGTH)],
            $this->exchangeFields($event, null, null)
        ));
        $this->eventResource->save($event);
    }

    /**
     * Record a failed delivery attempt; reschedules with backoff (or with the
     * delay Smaily itself asked for) or parks the event as failed once
     * attempts are exhausted.
     *
     * $terminal parks the row on the spot with its remaining attempts unspent:
     * a refusal that no amount of retrying can change (Failure decides
     * which those are). The attempt that WAS refused is still counted.
     */
    public function markFailed(
        Event $event,
        string $error,
        ?string $sentPayload = null,
        ?string $response = null,
        ?int $retryAfter = null,
        bool $terminal = false
    ): void {
        $attempts = $event->getAttempts() + 1;
        $exhausted = $terminal || $attempts >= self::MAX_ATTEMPTS;

        $event->addData([
            'attempts' => $attempts,
            'status' => $exhausted ? Event::STATUS_FAILED : Event::STATUS_PENDING,
            'next_retry_at' => $exhausted ? null : $this->nextRetryAt($attempts, $retryAfter),
            'last_error' => mb_substr($error, 0, self::MAX_ERROR_LENGTH),
        ] + $this->exchangeFields($event, $sentPayload, $response));
        $this->eventResource->save($event);

        if ($exhausted) {
            $this->logger->error('Queue event failed permanently', [
                'id' => $event->getId(),
                'event_type' => $event->getEventType(),
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
     * @return int number of rows reset
     */
    public function retry(array $ids): int
    {
        if (!$ids) {
            return 0;
        }

        $connection = $this->resourceConnection->getConnection();

        return $connection->update(
            $this->resourceConnection->getTableName(EventResource::TABLE_NAME),
            [
                'status' => Event::STATUS_PENDING,
                'attempts' => 0,
                'next_retry_at' => null,
            ],
            [
                'id IN (?)' => array_map('intval', $ids),
                'status = ?' => Event::STATUS_FAILED,
                'entity_id IS NULL OR entity_id != ?' => Erasure::PLACEHOLDER,
            ]
        );
    }

    /**
     * Withdraw a contact's still-pending automation rows of one trigger
     * (PRO-2453): the shopper bought, so the reminder must not go out. Only
     * `pending` rows are taken — a claimed one is a worker's.
     *
     * @return int number of rows cancelled
     */
    public function cancelPendingAutomation(string $trigger, string $entityId): int
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(EventResource::TABLE_NAME);

        $rows = $connection->fetchPairs(
            $connection->select()->from($table, ['id', 'payload'])
                ->where('event_type = ?', EventType::AUTOMATION_TRIGGER)
                ->where('entity_id = ?', $entityId)
                ->where('status = ?', Event::STATUS_PENDING)
        );

        $ids = [];
        foreach ($rows as $id => $payload) {
            if ($this->triggerOf((string)$payload) === $trigger) {
                $ids[] = (int)$id;
            }
        }
        if (!$ids) {
            return 0;
        }

        return $connection->update(
            $table,
            $this->terminalFields(self::CANCELLED_RESPONSE),
            [
                'id IN (?)' => $ids,
                'status = ?' => Event::STATUS_PENDING,
            ]
        );
    }

    /**
     * Whether an automation of one trigger went out to Smaily for a contact
     * (PRO-3619): a delivered row by deliveredCondition() — a skip, a
     * withdrawal, a row still waiting and one given up do not count.
     * Bounded by the janitor's retention of sent rows.
     */
    public function hasDeliveredAutomation(string $trigger, string $entityId): bool
    {
        $connection = $this->resourceConnection->getConnection();
        $payloads = $connection->fetchCol(
            $connection->select()
                ->from($this->resourceConnection->getTableName(EventResource::TABLE_NAME), ['payload'])
                ->where('event_type = ?', EventType::AUTOMATION_TRIGGER)
                ->where('entity_id = ?', $entityId)
                ->where($this->deliveredCondition())
        );

        foreach ($payloads as $payload) {
            if ($this->triggerOf((string)$payload) === $trigger) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which of these automation rows a later delivery already superseded:
     * one of the same trigger, to the same contact, that was delivered after
     * it (PRO-2454) — by deliveredCondition(), so a later row that was
     * skipped or withdrawn reached nobody and stands in no one's way
     * (PRO-3642). A trigger lives in the payload —
     * the Log's grid never carries it — so one query brings this queue's
     * automation rows for those contacts and the triggers are read here.
     *
     * @param array<int, string> $entityIds entity id by queue row id
     * @return int[] the ids among them that may not be sent again
     */
    public function laterDeliveredOfSameTrigger(array $entityIds): array
    {
        if (!$entityIds) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName(EventResource::TABLE_NAME),
                    [
                        'id',
                        'entity_id',
                        'payload',
                        'delivered' => new \Zend_Db_Expr(
                            'CASE WHEN ' . $this->deliveredCondition() . ' THEN 1 ELSE 0 END'
                        ),
                    ]
                )
                ->where('event_type = ?', EventType::AUTOMATION_TRIGGER)
                ->where('entity_id IN (?)', array_values(array_unique($entityIds)))
        );

        $byEntity = [];
        foreach ($rows as $row) {
            $byEntity[(string)$row['entity_id']][(int)$row['id']] = [
                'trigger' => $this->triggerOf((string)$row['payload']),
                'delivered' => (int)$row['delivered'] === 1,
            ];
        }

        $superseded = [];
        foreach ($entityIds as $id => $entityId) {
            $trigger = $byEntity[$entityId][$id]['trigger'] ?? '';
            if ($trigger === '') {
                continue;
            }
            foreach ($byEntity[$entityId] as $laterId => $later) {
                if ($laterId > $id && $later['delivered'] && $later['trigger'] === $trigger) {
                    $superseded[] = $id;
                    break;
                }
            }
        }

        return $superseded;
    }

    /**
     * Which of these addresses already have a profiling opt-out waiting for
     * the engine (PRO-3760): a consent row carrying an opt-out, pending —
     * a retry in its backoff too — or being sent. One query.
     *
     * @param string[] $emails
     * @return string[]
     */
    public function waitingProfilingOptOuts(array $emails): array
    {
        if (!$emails) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName(EventResource::TABLE_NAME), ['entity_id', 'payload'])
                ->where('event_type = ?', EventType::ENGINE_PROFILING_CONSENT)
                ->where('entity_id IN (?)', array_values($emails))
                ->where('status IN (?)', [Event::STATUS_PENDING, Event::STATUS_SENDING])
        );

        $waiting = [];
        foreach ($rows as $row) {
            if (($this->payloadDecoder->decode((string)$row['payload'])['opt_out'] ?? false) === true) {
                $waiting[(string)$row['entity_id']] = true;
            }
        }

        return array_keys($waiting);
    }

    /**
     * Decode an event payload.
     *
     * @return array<int|string, mixed>
     */
    public function decodePayload(Event $event): array
    {
        return Resend::stripRecord($this->payloadDecoder->decode($event->getPayload()));
    }

    /**
     * The one rule for "this row reached the contact", as SQL over a queue
     * row: closed as sent with a request on record, not withdrawn
     * (CANCELLED_RESPONSE) and with no skip reason in `last_error` — the
     * Log's own reading, where either marker makes the row Withdrawn or
     * Skipped. A skipped or withdrawn row may keep an earlier attempt's
     * request, so the request alone does not make a delivery.
     */
    private function deliveredCondition(): string
    {
        $connection = $this->resourceConnection->getConnection();

        return sprintf(
            "(status = %s AND sent_payload IS NOT NULL AND COALESCE(last_response, '') <> %s"
            . " AND COALESCE(last_error, '') = '')",
            $connection->quote(Event::STATUS_SENT),
            $connection->quote(self::CANCELLED_RESPONSE)
        );
    }

    /**
     * The automation trigger a stored payload was queued for.
     */
    private function triggerOf(string $payload): string
    {
        return (string)($this->payloadDecoder->decode($payload)['trigger_type'] ?? '');
    }

    /**
     * The fields that close a row for good — delivered, or withdrawn because
     * the reminder became moot: status sent and nothing left to retry, with
     * $response holding the API reply or CANCELLED_RESPONSE in its place, so
     * the Log keeps the row either way.
     *
     * @return array<string, mixed>
     */
    private function terminalFields(?string $response): array
    {
        return [
            'status' => Event::STATUS_SENT,
            'next_retry_at' => null,
            'last_response' => $response,
        ];
    }

    /**
     * The exchange an outcome stores: the one the caller names, else the one
     * the row already holds — recorded by this attempt, or left by an
     * earlier one. An attempt never erases the evidence before it (PRO-1963).
     *
     * @return array{sent_payload: mixed, last_response: mixed}
     */
    private function exchangeFields(Event $event, ?string $sentPayload, ?string $response): array
    {
        return [
            'sent_payload' => $sentPayload ?? $event->getData('sent_payload'),
            'last_response' => $response ?? $event->getData('last_response'),
        ];
    }

    private function nextRetryAt(int $attempts, ?int $retryAfter = null): string
    {
        // A delay Smaily asked for wins over the ladder, capped at the
        // ladder's own ceiling so a wild header cannot park a row for days.
        $backoff = $retryAfter !== null && $retryAfter > 0
            ? min($retryAfter, self::BACKOFF_SECONDS[count(self::BACKOFF_SECONDS) - 1])
            : self::BACKOFF_SECONDS[min($attempts, count(self::BACKOFF_SECONDS)) - 1];

        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $backoff);
    }
}
