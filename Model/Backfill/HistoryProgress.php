<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Client\FailureText;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Websites;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Where the history import (HistoryImport) stands, for the engine
 * (contract v1.20.0 §25, POST /api/v1/ingest/history/progress) and for the
 * Orders card (Controller/Adminhtml/Api/BackfillState), CON-241.
 *
 * The phase counts rows the engine accepted, never rows only queued:
 * `start_part_complete` once the start part's orders, and the rows of the
 * latest catalog and customers imports, are delivered and neither import
 * is queued or running; `complete` once the orders import has completed
 * and all its rows are delivered. A row the engine refused for good does
 * not hold a phase back. The start part stays complete once reached.
 *
 * The tick (Cron\BackfillTick) calls report() every minute: a phase change
 * is reported at once, and while the import runs, the counts at most every
 * REPORT_EVERY_SECONDS. A call that fails is logged and tried again after
 * REPORT_EVERY_SECONDS; it never stops or fails the import. Nothing is
 * reported once `complete` is in.
 *
 * A stopped import (contract v1.28.0 §25 "Stopped import", CON-367, as the
 * WooCommerce plugin's CON-284) gets ONE final report, at once, with the
 * phase it reached (never `complete`), its final counts and `stop_reason`,
 * and nothing after it:
 *   - its orders import was cancelled (`cancelled`), failed (`failed`) or
 *     stalled (`stalled`: JobManager::isStalled(), the import card's
 *     "Stalled", still so after the progress check has seen it for
 *     JobManager::STALLED_SECONDS — the WooCommerce plugin's test needs
 *     Action Scheduler, and the wait keeps a cron outage that ends from
 *     counting as one);
 *   - before its orders import began, its catalog or customers import was
 *     cancelled after the import began (`cancelled`, phase `started`; the
 *     report waits for that import's rows) — the cancel ends the sequence
 *     as in the WooCommerce plugin: the orders import queued behind it is
 *     cancelled too (JobManager::cancelEngineSequence()). Once the orders
 *     import runs, only it counts; a failed or stalled catalog or
 *     customers import never stops the import (the plugin's sequence moves
 *     on).
 * A part stopped because the account was refused or the connection is gone
 * (Job::REASON_ENGINE_NOT_ACTIVE) ends the import with no report: the
 * WooCommerce plugin pauses such an import and reports no stop, but
 * Cron\BackfillTick stops the job, so this one cannot resume. The report
 * waits while a row the stopped import queued is still undelivered, so its
 * counts are final, and an import the engine has heard nothing of is not
 * reported. Only Run again on the Orders card starts a new import, with a
 * new `import_id` (HistoryImport::begin()), as in the plugin.
 *
 * Each Campaign Intelligence connection's history import is reported on
 * its own (CON-237): its state, its imports (the jobs whose website_id is
 * its key), its sending gate, to its engine store through its own client.
 */
class HistoryProgress
{
    public const PHASE_STARTED = 'started';
    public const PHASE_START_PART_COMPLETE = 'start_part_complete';
    public const PHASE_COMPLETE = 'complete';

    /** At most this often while the import runs (§25 "When to call"), and after a failed call. */
    public const REPORT_EVERY_SECONDS = 300;

    /** §25 `stop_reason` values (v1.28.0). */
    public const STOP_CANCELLED = 'cancelled';
    public const STOP_FAILED = 'failed';
    public const STOP_STALLED = 'stalled';

    /** Kept in the state for an import a refusal ended; never sent (not a §25 value). */
    public const STOP_NOT_ACTIVE = 'not_active';

    public function __construct(
        private readonly HistoryImport $historyImport,
        private readonly JobManager $jobManager,
        private readonly IngestQueue $ingestQueue,
        private readonly Connections $connections,
        private readonly DateTime $dateTime,
        private readonly Websites $websites,
        private readonly Logger $logger
    ) {
    }

    /**
     * Report each connection's history import progress when it is due.
     * Never throws.
     */
    public function report(): void
    {
        try {
            $connections = $this->connections->all();
        } catch (\Throwable $exception) {
            $this->logger->error('Campaign Intelligence history progress could not be read', [
                'error' => FailureText::of($exception),
            ]);

            return;
        }
        foreach ($connections as $connection) {
            try {
                $this->reportDue($connection);
            } catch (\Throwable $exception) {
                $this->logger->error('Campaign Intelligence history progress could not be read', [
                    'error' => FailureText::of($exception),
                ]);
            }
        }
    }

    /**
     * What the Orders card shows of this orders import while it runs: the
     * part being sent, how much of it the engine has, and the window's
     * months; null when it is not the history import's job.
     *
     * @return array{part: string, sent: int, expected: int, percent: int, start_months: int,
     *     horizon_months: int}|null
     */
    public function card(Job $ordersJob): ?array
    {
        $connection = $this->connections->forKey($ordersJob->getWebsiteId());
        $state = $connection === null ? null : $this->historyImport->state($connection);
        if ($connection === null || $state === null || (int)$state['orders_job_id'] !== (int)$ordersJob->getId()) {
            return null;
        }

        $sent = (int)$ordersJob->getSentCount();
        if ($this->phase($state, $ordersJob, $connection) === self::PHASE_STARTED) {
            $part = 'start';
            $expected = (int)$state['start_expected'];
            $sent = min($sent, $expected);
        } else {
            $part = 'background';
            $expected = (int)$state['orders_expected'];
        }

        return [
            'part' => $part,
            'sent' => $sent,
            'expected' => $expected,
            'percent' => $expected > 0 ? (int)floor(min(100, $sent / $expected * 100)) : 100,
            'start_months' => (int)$state['start_months'],
            'horizon_months' => (int)$state['horizon_months'],
        ];
    }

    private function reportDue(Connection $connection): void
    {
        $state = $this->historyImport->state($connection);
        if ($state === null || $state['reported_phase'] === self::PHASE_COMPLETE
            || ($state['stop_reason'] ?? null) !== null
            || !$connection->settings()->isSendingAllowed()
        ) {
            return;
        }
        $ordersJob = $this->jobManager->get((int)$state['orders_job_id']);
        if ($ordersJob === null) {
            return;
        }

        $now = (int)$this->dateTime->gmtTimestamp();
        $stop = $this->stopped($state, $ordersJob, $connection);
        $stalledSince = (int)($state['stalled_since'] ?? 0);
        if ($stop !== null && $stop['reason'] === self::STOP_STALLED) {
            if ($stalledSince === 0) {
                $state = $this->historyImport->update($connection, ['stalled_since' => $now]);
            }
            if ($stalledSince === 0 || $now - $stalledSince < JobManager::STALLED_SECONDS) {
                $stop = null;
            }
        } elseif ($stalledSince !== 0) {
            $state = $this->historyImport->update($connection, ['stalled_since' => 0]);
        }
        // Stopped because the account was refused: it ends with no report.
        if ($stop !== null && $stop['reason'] === self::STOP_NOT_ACTIVE) {
            $this->historyImport->update($connection, ['stop_reason' => self::STOP_NOT_ACTIVE]);

            return;
        }
        // The engine has heard nothing of a stopped import: there is
        // nothing to stop. Its final counts wait for its queued rows.
        if ($stop !== null && ($state['reported_phase'] === null
            || $this->ingestQueue->countUndelivered([(int)$stop['job']->getId()]) > 0)
        ) {
            return;
        }

        $phase = $stop !== null && $stop['job']->getJobType() !== Job::TYPE_ORDERS
            ? self::PHASE_STARTED
            : $this->phase($state, $ordersJob, $connection);
        if ($phase !== self::PHASE_STARTED && !$state['start_part_done']) {
            $state = $this->historyImport->update($connection, ['start_part_done' => true]);
        }

        if ($now - (int)$state['failed_at'] < self::REPORT_EVERY_SECONDS
            || ($stop === null && $phase === $state['reported_phase']
                && $now - (int)$state['reported_at'] < self::REPORT_EVERY_SECONDS)
        ) {
            return;
        }

        $body = $this->body($state, $ordersJob, $phase, $connection);
        if ($stop !== null) {
            // The final report: the phase reached, the counts and why (§25 "Stopped import").
            unset($body['store_config']);
            $body['stop_reason'] = $stop['reason'];
        }
        try {
            $this->connections->client($connection)->historyProgress($body);
        } catch (\Throwable $exception) {
            $this->historyImport->update($connection, ['failed_at' => $now]);
            $this->logger->info('Campaign Intelligence history progress report failed', [
                'import_id' => $state['import_id'],
                'phase' => $phase,
                'error' => FailureText::of($exception),
            ]);

            return;
        }
        $this->historyImport->update(
            $connection,
            [
                'reported_phase' => $phase,
                'reported_at' => $now,
                'failed_at' => 0,
                'stop_reason' => $stop['reason'] ?? null,
            ]
        );
    }

    /**
     * Why the history import stopped before `complete`, with the job of the
     * part that stopped; null while it goes on. While the orders import has
     * not begun, the latest catalog or customers import cancelled after the
     * import began (an earlier one is not this import's; that cancel also
     * cancelled the orders import queued behind it,
     * JobManager::cancelEngineSequence()); then the orders import.
     *
     * @param array<string, mixed> $state HistoryImport::state()
     * @return array{reason: string, job: Job}|null
     */
    private function stopped(array $state, Job $ordersJob, Connection $connection): ?array
    {
        if ((string)$ordersJob->getData('started_at') === '') {
            foreach ([Job::TYPE_CATALOG, Job::TYPE_CUSTOMERS] as $jobType) {
                $job = $this->jobManager->latest($jobType, Job::TARGET_ENGINE, $connection->key());
                if ($job !== null && $job->getStatus() === Job::STATUS_CANCELLED
                    && (string)$job->getData('completed_at') >= (string)$state['until']
                ) {
                    return [
                        'reason' => $this->notActive($job) ? self::STOP_NOT_ACTIVE : self::STOP_CANCELLED,
                        'job' => $job,
                    ];
                }
            }
        }
        $reason = $this->stopReason($ordersJob, $connection);

        return $reason === null ? null : ['reason' => $reason, 'job' => $ordersJob];
    }

    /**
     * The §25 `stop_reason` of the orders import: cancelled, failed, or
     * queued or running but stalled (JobManager::isStalled());
     * STOP_NOT_ACTIVE when a refusal stopped it; null otherwise.
     */
    private function stopReason(Job $job, Connection $connection): ?string
    {
        if ($this->notActive($job)) {
            return self::STOP_NOT_ACTIVE;
        }

        return match ($job->getStatus()) {
            Job::STATUS_CANCELLED => self::STOP_CANCELLED,
            Job::STATUS_FAILED => self::STOP_FAILED,
            Job::STATUS_PENDING, Job::STATUS_RUNNING => $this->jobManager->isStalled(
                $job->getJobType(),
                Job::TARGET_ENGINE,
                $connection->key()
            ) ? self::STOP_STALLED : null,
            default => null,
        };
    }

    /** Whether the job stopped because its account was refused or its connection is gone. */
    private function notActive(Job $job): bool
    {
        return $job->getData('error_message') === Job::REASON_ENGINE_NOT_ACTIVE;
    }

    /**
     * @param array<string, mixed> $state HistoryImport::state()
     */
    private function phase(array $state, Job $ordersJob, Connection $connection): string
    {
        $jobId = (int)$ordersJob->getId();
        if ($ordersJob->getStatus() === Job::STATUS_COMPLETED && $this->ingestQueue->countUndelivered([$jobId]) === 0) {
            return self::PHASE_COMPLETE;
        }
        if ($state['start_part_done']) {
            return self::PHASE_START_PART_COMPLETE;
        }
        if ($state['start_part_queued_to'] === null
            || $this->ingestQueue->countUndelivered([$jobId], (int)$state['start_part_queued_to']) > 0
        ) {
            return self::PHASE_STARTED;
        }

        $before = [];
        foreach ([Job::TYPE_CATALOG, Job::TYPE_CUSTOMERS] as $type) {
            $job = $this->jobManager->latest($type, Job::TARGET_ENGINE, $connection->key());
            if ($job === null) {
                continue;
            }
            if (in_array($job->getStatus(), [Job::STATUS_PENDING, Job::STATUS_RUNNING], true)) {
                return self::PHASE_STARTED;
            }
            $before[] = (int)$job->getId();
        }

        return $this->ingestQueue->countUndelivered($before) > 0 ? self::PHASE_STARTED : self::PHASE_START_PART_COMPLETE;
    }

    /**
     * The §25 request: counts and dates only, never a person's data.
     *
     * @param array<string, mixed> $state HistoryImport::state()
     * @return array<string, mixed>
     */
    private function body(array $state, Job $ordersJob, string $phase, Connection $connection): array
    {
        $oldest = $this->ingestQueue->lastSentPayload((int)$ordersJob->getId())['ordered_at'] ?? null;

        $body = [
            'import_id' => $state['import_id'],
            'phase' => $phase,
            'horizon_start' => substr((string)$state['horizon'], 0, 10),
            'entities' => [
                'catalog' => $this->entity(Job::TYPE_CATALOG, $connection),
                'customers' => $this->entity(Job::TYPE_CUSTOMERS, $connection),
                'orders' => [
                    'expected' => (int)$state['orders_expected'],
                    'sent' => (int)$ordersJob->getSentCount(),
                    'oldest_sent_at' => is_string($oldest) && $oldest !== '' ? substr($oldest, 0, 10) : null,
                ],
                'buyers' => ['expected' => (int)$state['buyers_expected']],
            ],
        ];
        // How Magento shares customer accounts (v1.21.0, CON-253): a store
        // connected before it reports the setting with its next history
        // import, and a later change of the setting reaches the engine the
        // same way. Only `started` takes it.
        if ($phase === self::PHASE_STARTED) {
            $body['store_config'] = $this->websites->storeConfig();
        }

        return $body;
    }

    /**
     * The latest catalog or customers import's counts. One from before
     * accepted rows were counted reports the rows it queued.
     *
     * @return array{expected: int|null, sent: int}
     */
    private function entity(string $jobType, Connection $connection): array
    {
        $job = $this->jobManager->latest($jobType, Job::TARGET_ENGINE, $connection->key());
        if ($job === null) {
            return ['expected' => null, 'sent' => 0];
        }
        $total = $job->getData('total_count');

        return [
            'expected' => $total === null ? null : (int)$total,
            'sent' => $job->getSentCount() ?? $job->getProcessedCount(),
        ];
    }
}
