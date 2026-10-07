<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

/**
 * A Log row's attempts in order, for the Details panel (PRO-3565) — built
 * from what the queue row stores, with no table of its own. The row keeps
 * the number of failed attempts, its timestamps and its latest outcome only:
 * an earlier failure is listed without a time, and the last error belongs to
 * the latest failure alone, and only while that failure is the row's latest
 * change. A delivery does not raise the stored count, so it is the attempt
 * after the failures.
 */
class AttemptHistory
{
    public const QUEUED = 'queued';
    public const FAILED = 'failed';
    public const DELIVERED = 'delivered';
    public const SKIPPED = 'skipped';
    public const WITHDRAWN = 'withdrawn';
    public const SCHEDULED = 'scheduled';
    public const WAITING = 'waiting';
    public const IN_PROGRESS = 'in_progress';

    /**
     * @param array<string, mixed> $row the row as QueueRowLoader reads it
     * @return list<array{attempt: int|null, result: string, at: string|null, latest_error: bool}>
     */
    public function entries(array $row): array
    {
        if (!$row) {
            return [];
        }

        $status = (string)($row['status'] ?? '');
        $failures = (int)($row['attempts'] ?? 0);
        $updatedAt = $this->date($row['updated_at'] ?? null);
        $nextRetryAt = $this->date($row['next_retry_at'] ?? null);
        // The latest failure is the row's latest change only while the row
        // is parked by it or rescheduled after it.
        $failureIsLatest = $status === 'failed' || ($status === 'pending' && $nextRetryAt !== null);

        $entries = [$this->entry(null, self::QUEUED, $this->date($row['created_at'] ?? null))];
        for ($attempt = 1; $attempt <= $failures; $attempt++) {
            $latest = $failureIsLatest && $attempt === $failures;
            $entries[] = $this->entry($attempt, self::FAILED, $latest ? $updatedAt : null, $latest);
        }

        $next = $failures + 1;
        switch ($status) {
            case 'sent':
                $entries[] = $this->entry($next, self::DELIVERED, $updatedAt);
                break;
            case 'sending':
                $claimedAt = $this->date($row['claimed_at'] ?? null);
                $entries[] = $this->entry($next, self::IN_PROGRESS, $claimedAt ?? $updatedAt);
                break;
            case 'pending':
                $entries[] = $nextRetryAt !== null
                    ? $this->entry($next, self::SCHEDULED, $nextRetryAt)
                    : $this->entry($next, self::WAITING, null);
                break;
            case 'skipped':
                $entries[] = $this->entry(null, self::SKIPPED, $updatedAt);
                break;
            case 'withdrawn':
                $entries[] = $this->entry(null, self::WITHDRAWN, $updatedAt);
                break;
        }

        return $entries;
    }

    /**
     * @return array{attempt: int|null, result: string, at: string|null, latest_error: bool}
     */
    private function entry(?int $attempt, string $result, ?string $at, bool $latestError = false): array
    {
        return ['attempt' => $attempt, 'result' => $result, 'at' => $at, 'latest_error' => $latestError];
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
