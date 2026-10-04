<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\CatalogImportOnConnect;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3741 against a real MySQL: connecting queues one catalog import, a
 * reconnect while it is queued or running queues no second one, and holding
 * it back before its first cron run (the admin cancel) sends nothing and
 * leaves the import free to be started again by hand.
 */
class CatalogImportOnConnectTest extends IntegrationTestCase
{
    private const TABLE = 'smaily_backfill_job';

    private JobManager $jobManager;

    private CatalogImportOnConnect $catalogImport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->jobManager = $this->objectManager->create(JobManager::class);
        $this->catalogImport = $this->objectManager->create(
            CatalogImportOnConnect::class,
            ['jobManager' => $this->jobManager]
        );
    }

    public function testConnectingQueuesOneCatalogImportAndAReconnectNoSecond(): void
    {
        self::assertTrue($this->catalogImport->start());

        self::assertFalse($this->catalogImport->start(), 'Reconnect while the import is queued');
        $job = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($job);
        $this->jobManager->markRunning($job);
        self::assertFalse($this->catalogImport->start(), 'Reconnect while the import is running');

        $rows = $this->fetchAll(self::TABLE);
        self::assertCount(1, $rows);
        self::assertSame(Job::TYPE_CATALOG, $rows[0]['job_type']);
        self::assertSame(Job::TARGET_ENGINE, $rows[0]['target']);
        self::assertSame((string)Job::ENGINE_WEBSITE_ID, (string)$rows[0]['website_id']);
    }

    public function testHoldingTheImportBackBeforeItsFirstRunSendsNothing(): void
    {
        $this->catalogImport->start();

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
        self::assertSame(Job::STATUS_CANCELLED, $this->fetchAll(self::TABLE)[0]['status']);

        // Started later by hand, it runs as a fresh import.
        self::assertTrue($this->catalogImport->start());
    }
}
