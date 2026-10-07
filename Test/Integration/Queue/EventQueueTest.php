<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Queue;

use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * EventQueue semantics against a real smaily_event_queue table: idempotent
 * enqueue via the unique key, claim tokens, backoff scheduling, parking and
 * stale-claim recovery.
 */
class EventQueueTest extends IntegrationTestCase
{
    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
    }

    public function testEnqueuePersistsPendingRow(): void
    {
        $queued = $this->queue->enqueue('contact.sync', ['email' => 'a@example.com'], '42', 1, 'uuid-1');

        self::assertTrue($queued);
        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame('contact.sync', $rows[0]['event_type']);
        self::assertSame('42', $rows[0]['entity_id']);
        self::assertSame('uuid-1', $rows[0]['event_uuid']);
        self::assertSame('1', (string)$rows[0]['website_id']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame('0', (string)$rows[0]['attempts']);
        self::assertSame(['email' => 'a@example.com'], json_decode((string)$rows[0]['payload'], true));
    }

    public function testEnqueueWithDuplicateUuidIsSkippedByUniqueKey(): void
    {
        self::assertTrue($this->queue->enqueue('contact.sync', ['n' => 1], null, 0, 'dup-uuid'));
        self::assertFalse(
            $this->queue->enqueue('contact.sync', ['n' => 2], null, 0, 'dup-uuid'),
            'Second enqueue with the same UUID must be dropped by the real unique constraint'
        );

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame(['n' => 1], json_decode((string)$rows[0]['payload'], true), 'First payload must win');
    }

    public function testClaimBatchMarksRowsSendingAndIsExclusive(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-1');
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-2');

        $claimed = $this->queue->claimBatch();
        self::assertCount(2, $claimed);

        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_SENDING, $row['status']);
            self::assertNotEmpty($row['claim_token']);
            self::assertNotNull($row['claimed_at']);
        }

        self::assertSame([], $this->queue->claimBatch(), 'A second worker must not claim the same rows');
    }

    public function testClaimBatchSkipsRowsScheduledForTheFuture(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-due');
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-future');
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['next_retry_at' => $this->clockDate(3600)],
            ['event_uuid = ?' => 'u-future']
        );

        $claimed = $this->queue->claimBatch();

        self::assertCount(1, $claimed);
        self::assertSame('u-due', $claimed[0]->getEventUuid());

        // Once the clock passes the retry time the row becomes claimable.
        $this->clock->travel(3601);
        $claimed = $this->queue->claimBatch();
        self::assertCount(1, $claimed);
        self::assertSame('u-future', $claimed[0]->getEventUuid());
    }

    public function testMarkFailedFollowsBackoffScheduleAndParksAfterMaxAttempts(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-fail');

        foreach (EventQueue::BACKOFF_SECONDS as $attempt => $backoff) {
            $claimed = $this->queue->claimBatch();
            if ($attempt < EventQueue::MAX_ATTEMPTS - 1) {
                self::assertCount(1, $claimed, sprintf('Attempt %d must be claimable', $attempt + 1));
            }
            $this->queue->markFailed($claimed[0], 'API unavailable');

            $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
            self::assertSame((string)($attempt + 1), (string)$row['attempts']);

            if ($attempt + 1 < EventQueue::MAX_ATTEMPTS) {
                self::assertSame(Event::STATUS_PENDING, $row['status']);
                self::assertSame(
                    $this->clockDate($backoff),
                    $row['next_retry_at'],
                    sprintf('Attempt %d must be rescheduled +%ds', $attempt + 1, $backoff)
                );
                $this->clock->travel($backoff + 1);
            } else {
                self::assertSame(Event::STATUS_FAILED, $row['status'], 'Exhausted rows are parked as failed');
                self::assertNull($row['next_retry_at']);
                self::assertSame('API unavailable', $row['last_error']);
            }
        }

        self::assertSame([], $this->queue->claimBatch(), 'Parked rows must never be claimed');
    }

    public function testMarkSentStoresOutcomeAndClearsError(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-sent');
        $claimed = $this->queue->claimBatch();
        $this->queue->markFailed($claimed[0], 'transient');

        $this->clock->travel(61);
        $claimed = $this->queue->claimBatch();
        $this->queue->markSent($claimed[0], '{"sent":true}', 'HTTP 200');

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status']);
        self::assertNull($row['last_error']);
        self::assertSame('{"sent":true}', $row['sent_payload']);
        self::assertSame('HTTP 200', $row['last_response']);
    }

    public function testAnAttemptThatRecordsNoExchangeKeepsThePreviousAttemptsEvidence(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-evidence');
        $claimed = $this->queue->claimBatch();
        $this->queue->markFailed($claimed[0], 'HTTP 503', '[{"email":"a@example.com"}]', '{"http_status":503}');

        $this->clock->travel(61);
        $claimed = $this->queue->claimBatch();
        $this->queue->markFailed($claimed[0], 'Smaily API request failed: timed out');

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame('2', (string)$row['attempts']);
        self::assertSame('Smaily API request failed: timed out', $row['last_error']);
        self::assertSame('[{"email":"a@example.com"}]', $row['sent_payload'], 'PRO-1963: a retry erases nothing');
        self::assertSame('{"http_status":503}', $row['last_response'], 'PRO-1963: a retry erases nothing');
    }

    public function testAnExchangeRecordedOnTheRowIsStoredWithTheOutcome(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-recorded');
        $claimed = $this->queue->claimBatch();
        $this->queue->recordExchange($claimed[0], [['email' => 'a@example.com']], ['http_status' => 200]);
        $this->queue->markSent($claimed[0]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame([['email' => 'a@example.com']], json_decode((string)$row['sent_payload'], true));
        self::assertSame(['http_status' => 200], json_decode((string)$row['last_response'], true));
    }

    public function testRetryResetsOnlyFailedRows(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-parked');
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-ok');
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_FAILED, 'attempts' => 5],
            ['event_uuid = ?' => 'u-parked']
        );
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_SENT],
            ['event_uuid = ?' => 'u-ok']
        );

        $ids = array_map('intval', array_column($this->fetchAll(EventResource::TABLE_NAME), 'id'));
        self::assertSame(1, $this->queue->retry($ids), 'Only the failed row is reset');

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        $byUuid = array_column($rows, null, 'event_uuid');
        self::assertSame(Event::STATUS_PENDING, $byUuid['u-parked']['status']);
        self::assertSame('0', (string)$byUuid['u-parked']['attempts']);
        self::assertSame(Event::STATUS_SENT, $byUuid['u-ok']['status'], 'Sent rows must not be re-queued');
    }

    public function testRequeueStaleReturnsAbandonedClaimsToPending(): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-stale');
        $this->queue->claimBatch();

        self::assertSame(0, $this->queue->requeueStale(), 'A fresh claim is not stale');

        $this->clock->travel(901);
        self::assertSame(1, $this->queue->requeueStale());

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status']);
        self::assertNull($row['claim_token']);
    }

    /**
     * PRO-3961: an outcome dates the row at the time it happened and
     * releases the claim. The row model holds `updated_at` as the claim read
     * it; saving that back would leave the row dated at its previous change.
     *
     * @param \Closure(EventQueue, Event): void $outcome
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function testAnOutcomeDatesTheRowAtTheTimeItHappened(\Closure $outcome): void
    {
        $this->queue->enqueue('contact.sync', [], null, 0, 'u-dated');
        $this->pinUpdatedAt(['u-dated'], $this->clockDate(-7200));
        $claimed = $this->queue->claimBatch();
        self::assertCount(1, $claimed);

        $this->clock->travel(600);
        $outcome($this->queue, $claimed[0]);

        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame($this->clockDate(), $row['updated_at']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['claimed_at']);
    }

    /**
     * @return array<string, array{\Closure(EventQueue, Event): void}>
     */
    public static function outcomes(): array
    {
        return [
            'failure' => [static fn (EventQueue $queue, Event $event) => $queue->markFailed($event, 'HTTP 503')],
            'parking failure' => [
                static fn (EventQueue $queue, Event $event) => $queue->markFailed(
                    $event,
                    'permanent_http_404: gone',
                    terminal: true
                ),
            ],
            'delivery' => [
                static fn (EventQueue $queue, Event $event) => $queue->markSent($event, '[]', '{"code":101}'),
            ],
            'skip' => [static fn (EventQueue $queue, Event $event) => $queue->markSkipped($event, 'No workflow')],
        ];
    }

    /**
     * PRO-3961: the batched failure (PRO-1964) dates each row as a single
     * failure does — the row it parks and the rows it reschedules alike.
     */
    public function testABatchedFailureDatesEveryRowAtTheTimeItHappened(): void
    {
        foreach (['u-b1', 'u-b2', 'u-b3'] as $uuid) {
            $this->queue->enqueue('contact.sync', [], null, 0, $uuid);
        }
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['attempts' => EventQueue::MAX_ATTEMPTS - 1],
            ['event_uuid = ?' => 'u-b3']
        );
        $this->pinUpdatedAt(['u-b1', 'u-b2', 'u-b3'], $this->clockDate(-7200));
        $claimed = $this->queue->claimBatch();
        self::assertCount(3, $claimed);

        $this->clock->travel(600);
        $this->queue->markFailedMany($claimed, 'HTTP 503');

        $rows = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_FAILED, $rows['u-b3']['status']);
        foreach ($rows as $uuid => $row) {
            self::assertSame($this->clockDate(), $row['updated_at'], $uuid);
            self::assertNull($row['claim_token'], $uuid);
            self::assertNull($row['claimed_at'], $uuid);
        }
    }

    /**
     * PRO-2453: the shopper bought before the reminder went out, so the row
     * is withdrawn terminally — and only the matching trigger's, only for
     * this contact, and only while it is still pending.
     */
    public function testCancelPendingAutomationWithdrawsOnlyTheMatchingReminder(): void
    {
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-cancel');
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-claimed');
        $this->seedAutomation('welcome', 'shopper@example.com', 'u-welcome');
        $this->seedAutomation('abandoned_cart', 'someone-else@example.com', 'u-other');
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_SENDING],
            ['event_uuid = ?' => 'u-claimed']
        );

        self::assertSame(
            1,
            $this->queue->cancelPendingAutomation('abandoned_cart', 'shopper@example.com')
        );

        $byUuid = array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(Event::STATUS_SENT, $byUuid['u-cancel']['status'], 'Terminal: nothing retries it');
        self::assertSame(EventQueue::CANCELLED_RESPONSE, $byUuid['u-cancel']['last_response']);
        self::assertNull($byUuid['u-cancel']['sent_payload'], 'Nothing was POSTed');
        self::assertSame(Event::STATUS_SENDING, $byUuid['u-claimed']['status'], 'A claimed row is a worker\'s');
        self::assertSame(Event::STATUS_PENDING, $byUuid['u-welcome']['status']);
        self::assertSame(Event::STATUS_PENDING, $byUuid['u-other']['status']);

        self::assertSame(
            0,
            $this->queue->cancelPendingAutomation('abandoned_cart', 'shopper@example.com'),
            'Withdrawing twice is a no-op'
        );
    }

    /**
     * PRO-3619: only a reminder that went out to Smaily counts as delivered —
     * not one withdrawn, waiting, given up, or a skip that POSTed nothing.
     */
    public function testHasDeliveredAutomationCountsOnlyAReminderThatWentOut(): void
    {
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-pending');
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-failed');
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-skipped');
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-withdrawn');
        $this->seedAutomation('welcome', 'shopper@example.com', 'u-welcome');
        $this->seedAutomation('abandoned_cart', 'someone-else@example.com', 'u-other');
        $sent = ['status' => Event::STATUS_SENT, 'sent_payload' => '[{"email":"shopper@example.com"}]'];
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_FAILED, 'sent_payload' => $sent['sent_payload']],
            ['event_uuid = ?' => 'u-failed']
        );
        $this->connection->update(EventResource::TABLE_NAME, ['status' => Event::STATUS_SENT], [
            'event_uuid = ?' => 'u-skipped',
        ]);
        // A withdrawn row may keep the request of an earlier failed attempt.
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['sent_payload' => $sent['sent_payload'], 'last_response' => '{"http_status":503}'],
            ['event_uuid = ?' => 'u-withdrawn']
        );
        $this->queue->cancelPendingAutomation('abandoned_cart', 'shopper@example.com');
        $this->connection->update(EventResource::TABLE_NAME, $sent, ['event_uuid IN (?)' => ['u-welcome', 'u-other']]);

        self::assertFalse($this->queue->hasDeliveredAutomation('abandoned_cart', 'shopper@example.com'));

        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-delivered');
        $this->connection->update(EventResource::TABLE_NAME, $sent, ['event_uuid = ?' => 'u-delivered']);

        self::assertTrue($this->queue->hasDeliveredAutomation('abandoned_cart', 'shopper@example.com'));
    }

    /**
     * PRO-3642: a row skipped after an earlier failed attempt keeps that
     * attempt's request, but nothing reached the contact — the Log reads it
     * as Skipped, and it does not count as delivered.
     */
    public function testASkipThatKeptAnEarlierRequestIsNotADelivery(): void
    {
        $this->seedAutomation('abandoned_cart', 'shopper@example.com', 'u-1');
        $claimed = $this->queue->claimBatch();
        $this->queue->recordExchange($claimed[0], [['email' => 'shopper@example.com']], null);
        $this->queue->markFailed($claimed[0], 'Connection timed out');
        $this->clock->travel(3600);
        $claimed = $this->queue->claimBatch();
        $this->queue->markSkipped($claimed[0], 'Skipped: no Smaily workflow is mapped to this automation trigger.');

        self::assertFalse($this->queue->hasDeliveredAutomation('abandoned_cart', 'shopper@example.com'));
    }

    private function seedAutomation(string $trigger, string $email, string $uuid): void
    {
        $this->queue->enqueue(
            'automation.trigger',
            ['trigger_type' => $trigger, 'store_id' => 1, 'address' => ['email' => $email]],
            $email,
            0,
            $uuid
        );
    }

    /**
     * Date rows' last change at $at, as an earlier change would have left it.
     *
     * @param string[] $uuids
     */
    private function pinUpdatedAt(array $uuids, string $at): void
    {
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['updated_at' => $at],
            ['event_uuid IN (?)' => $uuids]
        );
    }
}
