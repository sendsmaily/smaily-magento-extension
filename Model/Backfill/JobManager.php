<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\ResourceModel\Backfill\Job as JobResource;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\Collection;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;

/**
 * Lifecycle management for chunked backfill jobs: one active job per
 * (job_type, target, website) at a time; the cron tick advances the oldest
 * active job that is not set aside (nextActive()) one time-budgeted chunk
 * per run.
 *
 * All status transitions are conditional single-statement UPDATEs so an
 * admin cancel can land at any moment without being overwritten by the
 * worker: the worker only writes progress columns between pages and checks
 * the persisted status at every page boundary (isCancelled), stopping
 * cleanly before the next page. A cancelled job is terminal — starting the
 * same import again creates a fresh job from the beginning.
 */
class JobManager
{
    /** An active job no tick has moved for this long counts as stalled (PRO-3854, PRO-3915). */
    public const STALLED_SECONDS = 3600;

    public function __construct(
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly CollectionFactory $collectionFactory,
        private readonly DateTime $dateTime,
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @throws \RuntimeException when an equivalent job is already active
     */
    public function start(string $jobType, string $target, int $websiteId, ?int $totalCount = null): Job
    {
        $job = $this->startIfIdle($jobType, $target, $websiteId, $totalCount);
        if ($job === null) {
            throw new \RuntimeException($this->alreadyActiveMessage($jobType, $target, $websiteId));
        }

        return $job;
    }

    /**
     * Queue the import unless an equivalent job is already active. A stalled
     * import of this kind (isStalled()) is cancelled first, so it cannot
     * block the new one — for every start: the admin's import card, the
     * smaily:backfill:start command and connecting Campaign Intelligence
     * (PRO-3915, PRO-3923).
     *
     * @return Job|null the new job, or null when one was already queued or running
     */
    public function startIfIdle(string $jobType, string $target, int $websiteId, ?int $totalCount = null): ?Job
    {
        if ($this->isStalled($jobType, $target)) {
            $this->requestCancel($jobType, $target);
        }
        if ($this->findActive($jobType, $target, $websiteId) !== null) {
            return null;
        }

        $job = $this->jobFactory->create();
        $job->addData([
            'job_type' => $jobType,
            'target' => $target,
            'website_id' => $websiteId,
            'status' => Job::STATUS_PENDING,
            'total_count' => $totalCount,
            'processed_count' => 0,
            'failed_count' => 0,
        ]);
        $this->jobResource->save($job);

        return $job;
    }

    /**
     * What a start says when an equivalent job is already active.
     */
    public function alreadyActiveMessage(string $jobType, string $target, int $websiteId): string
    {
        return (string)__('A "%1" import to %2 is already running for website %3.', $jobType, $target, $websiteId);
    }

    /**
     * The job the tick advances next: the oldest one that still needs work
     * and is not set aside. A job is set aside while it is running and its
     * own row has not been written for STALLED_SECONDS (updated_at, which
     * markRunning() and every recordProgress() refresh) — a worker that
     * dies on the same page every run. The imports queued behind it run,
     * and it is tried again when none waits (PRO-3927). A queued job is
     * never set aside: the tick has not taken it up yet.
     */
    public function nextActive(): ?Job
    {
        return $this->oldestActive(true) ?? $this->oldestActive(false);
    }

    private function oldestActive(bool $skipSetAside): ?Job
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [Job::STATUS_PENDING, Job::STATUS_RUNNING]]);
        if ($skipSetAside) {
            $this->skipSetAside($collection);
        }
        $collection->setOrder('id', 'ASC')->setPageSize(1);
        $job = $collection->getFirstItem();

        return $job instanceof Job && $job->getId() ? $job : null;
    }

    public function findActive(string $jobType, string $target, int $websiteId): ?Job
    {
        return $this->firstActive($jobType, $target, $websiteId);
    }

    /**
     * A queued or running job of this import — for one website, or for any
     * website when $websiteId is null; with $skipSetAside, one the tick has
     * not set aside (nextActive()).
     */
    private function firstActive(
        string $jobType,
        string $target,
        ?int $websiteId = null,
        bool $skipSetAside = false
    ): ?Job {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('status', ['in' => [Job::STATUS_PENDING, Job::STATUS_RUNNING]])
            ->addFieldToFilter('job_type', $jobType)
            ->addFieldToFilter('target', $target);
        if ($websiteId !== null) {
            $collection->addFieldToFilter('website_id', ['eq' => $websiteId]);
        }
        if ($skipSetAside) {
            $this->skipSetAside($collection);
        }
        $collection->setPageSize(1);
        $job = $collection->getFirstItem();

        return $job instanceof Job && $job->getId() ? $job : null;
    }

    /**
     * Whether an import of this kind is queued or running and the import
     * worker still moves (PRO-3854): some queued or running job — this one,
     * or the one ahead of it in line — was written within $stalledAfterSeconds.
     * The tick writes the job it advances at every page (recordProgress()),
     * and a job it has not reached yet waits behind one it does advance. A
     * job no tick has moved for that long (a worker that dies on the same
     * page every run) counts as stalled, so it cannot hold back what waits
     * for imports to finish forever. A job the tick has set aside
     * (nextActive()) does not move either, however busy the imports behind
     * it keep the worker (PRO-3950).
     */
    public function isActiveAndMoving(string $jobType, string $target, int $websiteId, int $stalledAfterSeconds): bool
    {
        if ($this->firstActive($jobType, $target, $websiteId, true) === null) {
            return false;
        }

        return $this->activeMovedWithin($stalledAfterSeconds);
    }

    /**
     * Whether an import of this kind is queued or running, for any website,
     * but stalled: one of its jobs is set aside (nextActive(), PRO-3927), or
     * no queued or running job was written within STALLED_SECONDS —
     * isActiveAndMoving()'s rule (PRO-3915). The admin's import card says
     * so, and starting the import again cancels it first (startIfIdle()).
     */
    public function isStalled(string $jobType, string $target): bool
    {
        $setAside = $this->collectionFactory->create();
        $setAside->addFieldToFilter('status', Job::STATUS_RUNNING)
            ->addFieldToFilter('job_type', $jobType)
            ->addFieldToFilter('target', $target)
            ->addFieldToFilter('updated_at', ['lt' => $this->stalledBefore()]);
        if ($setAside->getSize() > 0) {
            return true;
        }

        return $this->firstActive($jobType, $target) !== null && !$this->activeMovedWithin(self::STALLED_SECONDS);
    }

    /**
     * Whether some queued or running job was written within the last
     * $seconds — whether the worker still moves any import.
     */
    public function activeMovedWithin(int $seconds): bool
    {
        $lastMoved = (string)$this->connection()->fetchOne(
            $this->connection()->select()
                ->from($this->table(), ['last' => 'MAX(updated_at)'])
                ->where('status IN (?)', [Job::STATUS_PENDING, Job::STATUS_RUNNING])
        );

        return $lastMoved >= $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - $seconds
        );
    }

    /**
     * Leave out the jobs the tick has set aside (nextActive()): running, and
     * not written since stalledBefore(). isStalled() reads the same rule.
     */
    private function skipSetAside(Collection $collection): void
    {
        $collection->addFieldToFilter(
            ['status', 'updated_at'],
            [['eq' => Job::STATUS_PENDING], ['gteq' => $this->stalledBefore()]]
        );
    }

    /**
     * A job last written before this time has not moved for STALLED_SECONDS.
     */
    private function stalledBefore(): string
    {
        return $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - self::STALLED_SECONDS);
    }

    public function markRunning(Job $job): void
    {
        $updated = $this->connection()->update(
            $this->table(),
            [
                'status' => Job::STATUS_RUNNING,
                'started_at' => $this->dateTime->gmtDate(),
            ],
            [
                'id = ?' => (int)$job->getId(),
                'status = ?' => Job::STATUS_PENDING,
            ]
        );
        if ($updated > 0) {
            $job->setData('status', Job::STATUS_RUNNING);
        }
    }

    /**
     * Persist one page of progress. Writes ONLY the progress columns (plus
     * a total_count discovered by the processor), never the status — a
     * concurrent cancel stays cancelled.
     *
     * @param int $processedDelta rows handled in this chunk
     * @param int $failedDelta rows that failed in this chunk
     */
    public function recordProgress(Job $job, int $processedDelta, int $failedDelta, ?string $cursor): void
    {
        $job->addData([
            'processed_count' => $job->getProcessedCount() + $processedDelta,
            'failed_count' => $job->getFailedCount() + $failedDelta,
            'cursor_value' => $cursor,
        ]);
        $this->connection()->update(
            $this->table(),
            [
                'processed_count' => $job->getProcessedCount(),
                'failed_count' => $job->getFailedCount(),
                'cursor_value' => $cursor,
                'total_count' => $job->getData('total_count'),
            ],
            ['id = ?' => (int)$job->getId()]
        );
    }

    public function complete(Job $job): void
    {
        if ($this->finish($job, Job::STATUS_COMPLETED, ['total_count' => $job->getData('total_count')])) {
            $job->setData('status', Job::STATUS_COMPLETED);
        }
    }

    /**
     * Stop one job the way an admin cancel does, leaving the progress and
     * total it has already recorded untouched — for a worker that finds its
     * own reason to stop at a page boundary (PRO-1764: the contact-sync
     * switch turned off mid-import).
     */
    public function cancel(Job $job): void
    {
        if ($this->finish($job, Job::STATUS_CANCELLED)) {
            $job->setData('status', Job::STATUS_CANCELLED);
        }
    }

    public function fail(Job $job, string $error): void
    {
        if ($this->finish($job, Job::STATUS_FAILED, ['error_message' => mb_substr($error, 0, 60000)])) {
            $job->setData('status', Job::STATUS_FAILED);
        }
    }

    /**
     * Admin cancel: flips every active job of the import type to cancelled
     * in one conditional statement. The worker stops at its next page
     * boundary; starting the import again begins a fresh job.
     *
     * @return int number of jobs cancelled
     */
    public function requestCancel(string $jobType, string $target): int
    {
        return $this->connection()->update(
            $this->table(),
            [
                'status' => Job::STATUS_CANCELLED,
                'completed_at' => $this->dateTime->gmtDate(),
            ],
            [
                'job_type = ?' => $jobType,
                'target = ?' => $target,
                'status IN (?)' => [Job::STATUS_PENDING, Job::STATUS_RUNNING],
            ]
        );
    }

    /**
     * Fresh read of the persisted status — the page-boundary cancel check
     * for workers holding a possibly stale in-memory job.
     */
    public function isCancelled(Job $job): bool
    {
        $select = $this->connection()->select()
            ->from($this->table(), ['status'])
            ->where('id = ?', (int)$job->getId());

        return (string)$this->connection()->fetchOne($select) === Job::STATUS_CANCELLED;
    }

    /**
     * Terminal transition guarded against concurrent cancel: only a still
     * active row is moved, so cancelled always wins the race.
     *
     * @param array<string, mixed> $extra
     */
    private function finish(Job $job, string $status, array $extra = []): bool
    {
        $updated = $this->connection()->update(
            $this->table(),
            $extra + [
                'status' => $status,
                'completed_at' => $this->dateTime->gmtDate(),
            ],
            [
                'id = ?' => (int)$job->getId(),
                'status IN (?)' => [Job::STATUS_PENDING, Job::STATUS_RUNNING],
            ]
        );

        return $updated > 0;
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }

    private function table(): string
    {
        return $this->resourceConnection->getTableName(JobResource::TABLE_NAME);
    }
}
