<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
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
 * reported once `complete` is in, nor for an orders import that was
 * cancelled or stopped.
 */
class HistoryProgress
{
    public const PHASE_STARTED = 'started';
    public const PHASE_START_PART_COMPLETE = 'start_part_complete';
    public const PHASE_COMPLETE = 'complete';

    /** At most this often while the import runs (§25 "When to call"), and after a failed call. */
    public const REPORT_EVERY_SECONDS = 300;

    public function __construct(
        private readonly HistoryImport $historyImport,
        private readonly JobManager $jobManager,
        private readonly IngestQueue $ingestQueue,
        private readonly Client $client,
        private readonly EngineSettings $engineSettings,
        private readonly DateTime $dateTime,
        private readonly Logger $logger
    ) {
    }

    /**
     * Report the history import's progress when it is due. Never throws.
     */
    public function report(): void
    {
        try {
            $this->reportDue();
        } catch (\Throwable $exception) {
            $this->logger->error('Campaign Intelligence history progress could not be read', [
                'error' => $exception->getMessage(),
            ]);
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
        $state = $this->historyImport->state();
        if ($state === null || (int)$state['orders_job_id'] !== (int)$ordersJob->getId()) {
            return null;
        }

        $sent = (int)$ordersJob->getSentCount();
        if ($this->phase($state, $ordersJob) === self::PHASE_STARTED) {
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

    private function reportDue(): void
    {
        $state = $this->historyImport->state();
        if ($state === null || $state['reported_phase'] === self::PHASE_COMPLETE
            || !$this->engineSettings->isSendingAllowed()
        ) {
            return;
        }
        $ordersJob = $this->jobManager->get((int)$state['orders_job_id']);
        if ($ordersJob === null
            || in_array($ordersJob->getStatus(), [Job::STATUS_CANCELLED, Job::STATUS_FAILED], true)
        ) {
            return;
        }

        $phase = $this->phase($state, $ordersJob);
        if ($phase !== self::PHASE_STARTED && !$state['start_part_done']) {
            $state = $this->historyImport->update(['start_part_done' => true]);
        }

        $now = (int)$this->dateTime->gmtTimestamp();
        if ($now - (int)$state['failed_at'] < self::REPORT_EVERY_SECONDS
            || ($phase === $state['reported_phase'] && $now - (int)$state['reported_at'] < self::REPORT_EVERY_SECONDS)
        ) {
            return;
        }

        try {
            $this->client->historyProgress($this->body($state, $ordersJob, $phase));
        } catch (\Throwable $exception) {
            $this->historyImport->update(['failed_at' => $now]);
            $this->logger->info('Campaign Intelligence history progress report failed', [
                'import_id' => $state['import_id'],
                'phase' => $phase,
                'error' => $exception->getMessage(),
            ]);

            return;
        }
        $this->historyImport->update(['reported_phase' => $phase, 'reported_at' => $now, 'failed_at' => 0]);
    }

    /**
     * @param array<string, mixed> $state HistoryImport::state()
     */
    private function phase(array $state, Job $ordersJob): string
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
            $job = $this->jobManager->latest($type, Job::TARGET_ENGINE);
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
    private function body(array $state, Job $ordersJob, string $phase): array
    {
        $oldest = $this->ingestQueue->lastSentPayload((int)$ordersJob->getId())['ordered_at'] ?? null;

        return [
            'import_id' => $state['import_id'],
            'phase' => $phase,
            'horizon_start' => substr((string)$state['horizon'], 0, 10),
            'entities' => [
                'catalog' => $this->entity(Job::TYPE_CATALOG),
                'customers' => $this->entity(Job::TYPE_CUSTOMERS),
                'orders' => [
                    'expected' => (int)$state['orders_expected'],
                    'sent' => (int)$ordersJob->getSentCount(),
                    'oldest_sent_at' => is_string($oldest) && $oldest !== '' ? substr($oldest, 0, 10) : null,
                ],
                'buyers' => ['expected' => (int)$state['buyers_expected']],
            ],
        ];
    }

    /**
     * The latest catalog or customers import's counts. One from before
     * accepted rows were counted reports the rows it queued.
     *
     * @return array{expected: int|null, sent: int}
     */
    private function entity(string $jobType): array
    {
        $job = $this->jobManager->latest($jobType, Job::TARGET_ENGINE);
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
