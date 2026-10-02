<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\AttemptHistory;

/**
 * PRO-3565: Details lists a row's attempts in order, from what the queue
 * row stores — the attempt count, the timestamps and the latest outcome.
 * The row keeps no per-attempt record, so an earlier attempt has no time.
 */
class AttemptHistoryTest extends TestCase
{
    private const CREATED = '2026-10-02 10:00:00';
    private const UPDATED = '2026-10-02 10:20:00';
    private const NEXT = '2026-10-02 10:35:00';

    public function testANewRowIsQueuedAndWaitsForTheFirstAttempt(): void
    {
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(1, AttemptHistory::WAITING, null),
            ],
            (new AttemptHistory())->entries($this->row('pending', 0))
        );
    }

    public function testARetryingRowListsItsFailuresAndTheScheduledAttempt(): void
    {
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(1, AttemptHistory::FAILED, null),
                $this->entry(2, AttemptHistory::FAILED, self::UPDATED, true),
                $this->entry(3, AttemptHistory::SCHEDULED, self::NEXT),
            ],
            (new AttemptHistory())->entries($this->row('pending', 2, ['next_retry_at' => self::NEXT]))
        );
    }

    public function testAFailedRowEndsWithItsLastFailure(): void
    {
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(1, AttemptHistory::FAILED, self::UPDATED, true),
            ],
            (new AttemptHistory())->entries($this->row('failed', 1))
        );
    }

    public function testADeliveredRowCountsTheDeliveryAsAnAttempt(): void
    {
        // A delivery does not raise the stored count — only a failure does.
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(1, AttemptHistory::FAILED, null),
                $this->entry(2, AttemptHistory::DELIVERED, self::UPDATED),
            ],
            (new AttemptHistory())->entries($this->row('sent', 1))
        );
    }

    public function testARowBeingSentShowsTheAttemptInProgressSinceItsClaim(): void
    {
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(1, AttemptHistory::IN_PROGRESS, '2026-10-02 10:30:00'),
            ],
            (new AttemptHistory())->entries($this->row('sending', 0, ['claimed_at' => '2026-10-02 10:30:00']))
        );
    }

    public function testASkippedRowIsClosedWithoutAnAttempt(): void
    {
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(null, AttemptHistory::SKIPPED, self::UPDATED),
            ],
            (new AttemptHistory())->entries($this->row('skipped', 0))
        );
    }

    public function testAWithdrawnRowKeepsTheFailureBeforeIt(): void
    {
        // The withdrawal is the row's latest change: the failure before it
        // has no time of its own any more, and the last error is not its.
        self::assertSame(
            [
                $this->entry(null, AttemptHistory::QUEUED, self::CREATED),
                $this->entry(1, AttemptHistory::FAILED, null),
                $this->entry(null, AttemptHistory::WITHDRAWN, self::UPDATED),
            ],
            (new AttemptHistory())->entries($this->row('withdrawn', 1))
        );
    }

    public function testAnEmptyRowHasNoHistory(): void
    {
        self::assertSame([], (new AttemptHistory())->entries([]));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function row(string $status, int $attempts, array $extra = []): array
    {
        return $extra + [
            'status' => $status,
            'attempts' => (string)$attempts,
            'created_at' => self::CREATED,
            'updated_at' => self::UPDATED,
            'next_retry_at' => null,
            'claimed_at' => null,
        ];
    }

    /**
     * @return array{attempt: int|null, result: string, at: string|null, latest_error: bool}
     */
    private function entry(?int $attempt, string $result, ?string $at, bool $latestError = false): array
    {
        return ['attempt' => $attempt, 'result' => $result, 'at' => $at, 'latest_error' => $latestError];
    }
}
