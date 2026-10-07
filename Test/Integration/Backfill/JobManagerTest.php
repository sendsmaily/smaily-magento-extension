<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * Backfill job lifecycle against a real MySQL: the page-boundary cancel
 * semantics — an admin cancel wins every race against the worker's own
 * writes (progress, complete, fail), and a cancelled import restarts from
 * scratch.
 */
class JobManagerTest extends IntegrationTestCase
{
    private const TABLE = 'smaily_backfill_job';

    private JobManager $jobManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobManager = $this->objectManager->create(JobManager::class);
    }

    public function testStartCreatesPendingJobAndRejectsDuplicateActive(): void
    {
        $job = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1, 100);

        $row = $this->fetchRow(self::TABLE, (int)$job->getId());
        self::assertSame(Job::STATUS_PENDING, $row['status']);
        self::assertSame('100', (string)$row['total_count']);

        $this->expectException(\RuntimeException::class);
        $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
    }

    public function testStartIfIdleQueuesOneJobAndAnswersNullWhileItIsActive(): void
    {
        $job = $this->jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);

        self::assertNotNull($job);
        self::assertSame(Job::STATUS_PENDING, $this->fetchRow(self::TABLE, (int)$job->getId())['status']);
        self::assertNull($this->jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0));
        self::assertCount(1, $this->fetchAll(self::TABLE));
    }

    public function testRecordProgressPersistsCountsCursorAndDiscoveredTotal(): void
    {
        $job = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        $this->jobManager->markRunning($job);

        // Processors discover total_count lazily and stash it on the model.
        $job->setData('total_count', 42);
        $this->jobManager->recordProgress($job, 10, 2, '123');
        $this->jobManager->recordProgress($job, 5, 0, '456');

        $row = $this->fetchRow(self::TABLE, (int)$job->getId());
        self::assertSame(Job::STATUS_RUNNING, $row['status']);
        self::assertSame('15', (string)$row['processed_count']);
        self::assertSame('2', (string)$row['failed_count']);
        self::assertSame('456', $row['cursor_value']);
        self::assertSame('42', (string)$row['total_count']);
    }

    public function testRequestCancelStopsPendingAndRunningJobsOfType(): void
    {
        $pending = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $running = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 2);
        $this->jobManager->markRunning($running);
        $other = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);

        $cancelled = $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);

        self::assertSame(2, $cancelled);
        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$pending->getId())['status']);
        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$running->getId())['status']);
        self::assertSame(Job::STATUS_PENDING, $this->fetchRow(self::TABLE, (int)$other->getId())['status']);
        self::assertNotEmpty($this->fetchRow(self::TABLE, (int)$running->getId())['completed_at']);
    }

    public function testWorkerSeesCancelAtPageBoundaryAndProgressWriteKeepsIt(): void
    {
        // Worker holds an in-memory job loaded before the admin cancels.
        $job = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->markRunning($job);
        self::assertFalse($this->jobManager->isCancelled($job));

        // Admin cancel lands mid-chunk...
        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);

        // ...the worker still finishes its page and records progress —
        // which must NOT resurrect the job...
        $this->jobManager->recordProgress($job, 500, 0, '500');
        $row = $this->fetchRow(self::TABLE, (int)$job->getId());
        self::assertSame(Job::STATUS_CANCELLED, $row['status']);
        self::assertSame('500', (string)$row['processed_count']);

        // ...and the page-boundary check tells it to stop.
        self::assertTrue($this->jobManager->isCancelled($job));
    }

    public function testCompleteAndFailDoNotOverwriteConcurrentCancel(): void
    {
        $job = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->markRunning($job);
        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);

        $this->jobManager->complete($job);
        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$job->getId())['status']);

        $this->jobManager->fail($job, 'boom');
        $row = $this->fetchRow(self::TABLE, (int)$job->getId());
        self::assertSame(Job::STATUS_CANCELLED, $row['status']);
        self::assertEmpty($row['error_message']);
    }

    public function testMarkRunningDoesNotResurrectCancelledJob(): void
    {
        $job = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);

        $this->jobManager->markRunning($job);

        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$job->getId())['status']);
    }

    public function testCancelledImportRestartsAsAFreshJob(): void
    {
        $job = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1, 100);
        $this->jobManager->markRunning($job);
        $this->jobManager->recordProgress($job, 40, 1, '40');
        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);

        // Cancelled is terminal: the slot is free again.
        self::assertNull($this->jobManager->findActive(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1));

        $fresh = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1, 100);
        $row = $this->fetchRow(self::TABLE, (int)$fresh->getId());
        self::assertNotSame((int)$job->getId(), (int)$fresh->getId());
        self::assertSame('0', (string)$row['processed_count']);
        self::assertNull($row['cursor_value']);
    }

    public function testCompleteAndFailTransitionsForActiveJobs(): void
    {
        $completing = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->markRunning($completing);
        $this->jobManager->complete($completing);
        $row = $this->fetchRow(self::TABLE, (int)$completing->getId());
        self::assertSame(Job::STATUS_COMPLETED, $row['status']);
        self::assertSame(Job::STATUS_COMPLETED, $completing->getStatus());
        self::assertNotEmpty($row['completed_at']);

        $failing = $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, 0);
        $this->jobManager->fail($failing, 'engine unreachable');
        $row = $this->fetchRow(self::TABLE, (int)$failing->getId());
        self::assertSame(Job::STATUS_FAILED, $row['status']);
        self::assertSame('engine unreachable', $row['error_message']);
    }

    /**
     * PRO-3854: what the nightly catalog manifest waits for. An import is in
     * progress while it is queued or running and the import worker still
     * moves; one nothing has moved for the bound is stalled and holds
     * nothing back (Woo PRO-3886's lesson).
     */
    public function testAnImportIsInProgressWhileTheImportWorkerMovesWithinTheBound(): void
    {
        self::assertFalse($this->inProgress(), 'no catalog import at all');

        $catalog = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        $this->movedAt($catalog, -60);
        self::assertTrue($this->inProgress(), 'queued, written a minute ago');

        $this->movedAt($catalog, -3601);
        self::assertFalse($this->inProgress(), 'nothing has moved it for over an hour');

        // Waiting its turn behind a contacts import the worker does advance.
        $contacts = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->movedAt($contacts, -30);
        self::assertTrue($this->inProgress(), 'waits behind an import that moves');

        $this->movedAt($contacts, -7200);
        self::assertFalse($this->inProgress(), 'the import ahead stalled too');

        $this->jobManager->complete($catalog);
        $this->movedAt($catalog, 0);
        self::assertFalse($this->inProgress(), 'a finished import holds nothing back');
    }

    /**
     * PRO-3915: the import card's stalled state reads the same rule — a
     * queued or running import, for any website, that no tick has moved for
     * an hour, its own or the one ahead of it in line.
     */
    public function testAnImportIsStalledOnceNothingHasMovedItForAnHour(): void
    {
        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY), 'no import at all');

        $contacts = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 2);
        $this->jobManager->markRunning($contacts);
        $this->movedAt($contacts, -60);
        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY), 'moved a minute ago');

        $this->movedAt($contacts, -JobManager::STALLED_SECONDS - 1);
        self::assertTrue($this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY), 'not moved for an hour');
        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CATALOG, Job::TARGET_ENGINE), 'another import');

        $catalog = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        $this->movedAt($catalog, -30);
        self::assertFalse(
            $this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY),
            'the worker still moves an import'
        );

        $this->jobManager->requestCancel(Job::TYPE_CATALOG, Job::TARGET_ENGINE);
        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);
        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY), 'canceled');
    }

    /**
     * PRO-3915, PRO-3923: every start — the import card, the command line,
     * connecting Campaign Intelligence — cancels a stalled import of its
     * kind first, so the stalled one cannot block it.
     */
    public function testStartIfIdleCancelsAStalledImportOfItsKindFirst(): void
    {
        $first = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $second = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 2);
        $this->jobManager->markRunning($first);
        $this->movedAt($first, -JobManager::STALLED_SECONDS - 1);
        $this->movedAt($second, -JobManager::STALLED_SECONDS - 1);

        $fresh = $this->jobManager->startIfIdle(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        self::assertNotNull($fresh);
        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$first->getId())['status']);
        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$second->getId())['status']);

        // The fresh job moves, so the next website's start cancels nothing.
        self::assertNotNull($this->jobManager->startIfIdle(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 2));
        self::assertSame(Job::STATUS_PENDING, $this->fetchRow(self::TABLE, (int)$fresh->getId())['status']);
    }

    private function inProgress(): bool
    {
        return $this->jobManager->isActiveAndMoving(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0, 3600);
    }

    private function movedAt(Job $job, int $offsetSeconds): void
    {
        $this->connection->update(
            self::TABLE,
            ['updated_at' => $this->clockDate($offsetSeconds)],
            ['id = ?' => (int)$job->getId()]
        );
    }
}
