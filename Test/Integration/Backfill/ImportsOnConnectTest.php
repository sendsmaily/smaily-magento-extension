<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3741, PRO-3790 against a real MySQL: connecting queues one catalog
 * import, then one customers import, and no orders import; the tick takes
 * the catalog first. A reconnect while they are queued or running queues no
 * second one, and holding the catalog back before its first cron run (the
 * admin cancel) sends nothing and leaves the import free to be started
 * again by hand.
 */
class ImportsOnConnectTest extends IntegrationTestCase
{
    private const TABLE = 'smaily_backfill_job';

    private JobManager $jobManager;

    private ImportsOnConnect $importsOnConnect;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobManager = $this->objectManager->create(JobManager::class);
        $this->importsOnConnect = $this->objectManager->create(
            ImportsOnConnect::class,
            ['jobManager' => $this->jobManager]
        );
    }

    public function testConnectingQueuesTheCatalogThenTheCustomersImportAndNoOrdersImport(): void
    {
        self::assertSame(
            [Job::TYPE_CATALOG => true, Job::TYPE_CUSTOMERS => true],
            $this->importsOnConnect->start()
        );

        $rows = $this->fetchAll(self::TABLE);
        usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        self::assertSame(
            [Job::TYPE_CATALOG, Job::TYPE_CUSTOMERS],
            array_column($rows, 'job_type'),
            'The catalog import is queued first, then the customers import; no orders import'
        );
        foreach ($rows as $row) {
            self::assertSame(Job::TARGET_ENGINE, $row['target']);
            self::assertSame((string)Job::ENGINE_WEBSITE_ID, (string)$row['website_id']);
            self::assertSame(Job::STATUS_PENDING, $row['status']);
        }
        $next = $this->jobManager->nextActive();
        self::assertNotNull($next);
        self::assertSame(Job::TYPE_CATALOG, $next->getData('job_type'), 'The tick runs the catalog import first');
    }

    public function testAReconnectWhileTheImportsAreQueuedOrRunningQueuesNoSecond(): void
    {
        $this->importsOnConnect->start();

        self::assertSame(
            [Job::TYPE_CATALOG => false, Job::TYPE_CUSTOMERS => false],
            $this->importsOnConnect->start(),
            'Reconnect while the imports are queued'
        );
        $job = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($job);
        $this->jobManager->markRunning($job);
        self::assertSame(
            [Job::TYPE_CATALOG => false, Job::TYPE_CUSTOMERS => false],
            $this->importsOnConnect->start(),
            'Reconnect while the catalog import is running'
        );

        self::assertCount(2, $this->fetchAll(self::TABLE));
    }

    /**
     * PRO-3923: a stalled catalog import does not keep a connect from
     * starting one, as Run again on the import card does.
     */
    public function testConnectingWhileTheCatalogImportHasStalledStartsAFreshOne(): void
    {
        $this->importsOnConnect->start();
        $stalled = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($stalled);
        $this->jobManager->markRunning($stalled);
        $this->connection->update(
            self::TABLE,
            ['updated_at' => $this->clockDate(-JobManager::STALLED_SECONDS - 1)],
            ['id = ?' => (int)$stalled->getId()]
        );

        self::assertSame(
            [Job::TYPE_CATALOG => true, Job::TYPE_CUSTOMERS => false],
            $this->importsOnConnect->start()
        );

        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$stalled->getId())['status']);
        $fresh = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($fresh);
        self::assertSame(Job::STATUS_PENDING, $fresh->getStatus());
    }

    public function testHoldingTheCatalogImportBackBeforeItsFirstRunSendsNothing(): void
    {
        $this->importsOnConnect->start();
        // The customers import is not held back; it is cancelled here only
        // so that the tick below has nothing to run.
        $this->jobManager->requestCancel(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE);

        // Hold back: the Settings > Intelligence endpoint's cancel.
        self::assertSame(1, $this->jobManager->requestCancel(Job::TYPE_CATALOG, Job::TARGET_ENGINE));

        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::never())->method('process');
        $this->objectManager->create(BackfillTick::class, [
            'jobManager' => $this->jobManager,
            'engineSettings' => $this->createMock(EngineSettings::class),
            'processors' => ['catalog:engine' => $processor],
        ])->execute();

        self::assertSame([], $this->fetchAll(IngestEventResource::TABLE_NAME));
        self::assertSame(
            [Job::STATUS_CANCELLED, Job::STATUS_CANCELLED],
            array_column($this->fetchAll(self::TABLE), 'status')
        );

        // Started later by hand, it runs as a fresh import.
        self::assertNotNull(
            $this->jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
        );
    }
}
