<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\EngineCatalogProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Logger\Logger;
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
     * PRO-4007: the customers-import notice asks for the import until one
     * has completed; a queued, running, cancelled or failed one does not
     * count, nor does a completed import of another type.
     */
    public function testHasCompletedOnlyOnceAnImportOfThatKindHasCompleted(): void
    {
        $completed = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        $this->jobManager->complete($completed);
        $this->jobManager->start(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, 0);
        $this->jobManager->requestCancel(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE);
        $this->jobManager->fail($this->jobManager->start(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, 0), 'down');
        $running = $this->jobManager->start(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, 0);
        $this->jobManager->markRunning($running);

        self::assertFalse($this->jobManager->hasCompleted(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE));

        $this->jobManager->complete($running);

        self::assertTrue($this->jobManager->hasCompleted(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE));
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
     * an hour, its own or the one ahead of it in line. PRO-3927: a running
     * one the tick has set aside reads as stalled while it runs others.
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
        self::assertTrue(
            $this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY),
            'set aside while the worker moves another import'
        );

        $this->jobManager->requestCancel(Job::TYPE_CATALOG, Job::TARGET_ENGINE);
        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);
        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY), 'canceled');
    }

    /**
     * A queued import waits behind one the worker moves, however long that
     * takes; the wait is not its own stall.
     */
    public function testAQueuedImportWaitingBehindOneThatMovesIsNotStalled(): void
    {
        $catalog = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        $this->jobManager->markRunning($catalog);
        $contacts = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->movedAt($catalog, -30);
        $this->movedAt($contacts, -2 * JobManager::STALLED_SECONDS);

        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY));
        self::assertSame($catalog->getId(), $this->jobManager->nextActive()?->getId());
    }

    /**
     * PRO-3927: a job the tick dies on the same page every run is set aside:
     * the tick runs the one queued behind it, and takes the stalled one again
     * once nothing else waits.
     */
    public function testTheTickRunsTheImportBehindOneThatHasStalled(): void
    {
        $stalled = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->markRunning($stalled);
        $this->movedAt($stalled, -JobManager::STALLED_SECONDS - 1);
        $behind = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);

        // The real tick, with a processor that records the job it is given
        // and moves it one page.
        $recorder = new class ($this->jobManager) implements ProcessorInterface {
            /** @var int[] */
            public array $ran = [];

            public function __construct(private readonly JobManager $jobManager)
            {
            }

            public function process(Job $job): void
            {
                $this->ran[] = (int)$job->getId();
                $this->jobManager->markRunning($job);
                $this->jobManager->recordProgress($job, 1, 0, (string)count($this->ran));
            }
        };
        $tick = new BackfillTick(
            $this->jobManager,
            $this->createMock(EngineSettings::class),
            $this->createMock(Logger::class),
            ['contacts:smaily' => $recorder, 'catalog:engine' => $recorder]
        );

        $tick->execute();
        self::assertSame([(int)$behind->getId()], $recorder->ran, 'the import behind runs');
        self::assertSame(Job::STATUS_RUNNING, $this->fetchRow(self::TABLE, (int)$behind->getId())['status']);
        self::assertTrue(
            $this->jobManager->isStalled(Job::TYPE_CONTACTS, Job::TARGET_SMAILY),
            'the set-aside import still reads as stalled'
        );

        $this->jobManager->complete($behind);
        $tick->execute();
        self::assertSame([(int)$behind->getId(), (int)$stalled->getId()], $recorder->ran, 'then the stalled one again');
    }

    /**
     * PRO-3950: a worker that dies before the first page — here while the
     * catalog import counts its products, the process gone before the tick
     * could record a failure — leaves a running job, not a queued one. It
     * keeps its place for an hour and then is set aside like any other, so
     * the import behind it runs.
     */
    public function testAnImportThatDiesBeforeItsFirstPageDoesNotBlockTheImportsBehindIt(): void
    {
        require_once __DIR__ . '/../Support/Stub/ProductCollectionFactory.php';
        $collection = $this->createMock(ProductCollection::class);
        $collection->method('getSize')->willThrowException(new \RuntimeException('worker died counting'));
        $collectionFactory = $this->createMock(ProductCollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        $dying = new EngineCatalogProcessor(
            $this->jobManager,
            $collectionFactory,
            $this->createMock(CatalogPayloadBuilder::class),
            $this->objectManager->create(IngestQueue::class),
            $this->createMock(CatalogIngest::class),
            $this->createMock(CatalogProductLoader::class)
        );

        $front = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        $behind = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        try {
            $dying->process($front);
            self::fail('the count should have died');
        } catch (\RuntimeException) {
            // The process ends here: nothing records a failure.
        }
        self::assertSame(Job::STATUS_RUNNING, $this->fetchRow(self::TABLE, (int)$front->getId())['status']);
        self::assertSame($front->getId(), $this->jobManager->nextActive()?->getId(), 'keeps its place for the hour');
        self::assertFalse($this->jobManager->isStalled(Job::TYPE_CATALOG, Job::TARGET_ENGINE), 'not stalled yet');

        $this->movedAt($front, -JobManager::STALLED_SECONDS - 1);
        $recorder = new class ($this->jobManager) implements ProcessorInterface {
            /** @var int[] */
            public array $ran = [];

            public function __construct(private readonly JobManager $jobManager)
            {
            }

            public function process(Job $job): void
            {
                $this->ran[] = (int)$job->getId();
                $this->jobManager->markRunning($job);
                $this->jobManager->recordProgress($job, 1, 0, '1');
            }
        };
        $tick = new BackfillTick(
            $this->jobManager,
            $this->createMock(EngineSettings::class),
            $this->createMock(Logger::class),
            ['catalog:engine' => $dying, 'contacts:smaily' => $recorder]
        );

        $tick->execute();
        self::assertSame([(int)$behind->getId()], $recorder->ran, 'the import behind runs');
        self::assertSame(Job::STATUS_RUNNING, $this->fetchRow(self::TABLE, (int)$front->getId())['status']);
        self::assertTrue($this->jobManager->isStalled(Job::TYPE_CATALOG, Job::TARGET_ENGINE), 'the card says stalled');
    }

    public function testALoneStalledJobIsStillTakenEveryRun(): void
    {
        $stalled = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->markRunning($stalled);
        $this->movedAt($stalled, -JobManager::STALLED_SECONDS - 1);

        self::assertSame($stalled->getId(), $this->jobManager->nextActive()?->getId());
    }

    /**
     * Only a running job that has not moved for STALLED_SECONDS is set
     * aside: one that started within the hour, or one still queued, keeps
     * its place in line.
     */
    public function testAJobThatHadNoChanceYetKeepsItsPlace(): void
    {
        $started = $this->jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->jobManager->markRunning($started);
        $this->movedAt($started, -JobManager::STALLED_SECONDS + 60);
        $behind = $this->jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        self::assertSame($started->getId(), $this->jobManager->nextActive()?->getId(), 'started within the hour');

        $this->jobManager->requestCancel(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);
        $this->movedAt($behind, -2 * JobManager::STALLED_SECONDS);
        $this->jobManager->start(Job::TYPE_ORDERS, Job::TARGET_ENGINE, 0);
        self::assertSame($behind->getId(), $this->jobManager->nextActive()?->getId(), 'queued for two hours');
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
