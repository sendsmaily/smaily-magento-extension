<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3741, PRO-3790, CON-241 against a real MySQL: connecting queues one
 * catalog import, then one customers import, then one orders import; the
 * tick takes the catalog first. A reconnect while they are queued or running queues no
 * second one, and holding the catalog back before its first cron run (the
 * admin cancel) sends nothing, cancels the customers and orders imports
 * queued behind it (CON-367) and leaves the imports free to be started
 * again by hand.
 *
 * CON-237: the imports are the connected connection's — their jobs carry
 * its key — and another connection's imports neither block nor are
 * cancelled by them.
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

    public function testConnectingQueuesTheCatalogThenTheCustomersThenTheOrdersImport(): void
    {
        self::assertSame(
            [Job::TYPE_CATALOG => true, Job::TYPE_CUSTOMERS => true, Job::TYPE_ORDERS => true],
            $this->importsOnConnect->start($this->connection(0))
        );

        $rows = $this->fetchAll(self::TABLE);
        usort($rows, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
        self::assertSame(
            [Job::TYPE_CATALOG, Job::TYPE_CUSTOMERS, Job::TYPE_ORDERS],
            array_column($rows, 'job_type'),
            'The catalog import is queued first, then the customers import, then the orders import'
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
        $this->importsOnConnect->start($this->connection(0));

        self::assertSame(
            [Job::TYPE_CATALOG => false, Job::TYPE_CUSTOMERS => false, Job::TYPE_ORDERS => false],
            $this->importsOnConnect->start($this->connection(0)),
            'Reconnect while the imports are queued'
        );
        $job = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($job);
        $this->jobManager->markRunning($job);
        self::assertSame(
            [Job::TYPE_CATALOG => false, Job::TYPE_CUSTOMERS => false, Job::TYPE_ORDERS => false],
            $this->importsOnConnect->start($this->connection(0)),
            'Reconnect while the catalog import is running'
        );

        self::assertCount(3, $this->fetchAll(self::TABLE));
    }

    /**
     * PRO-3923: a stalled catalog import does not keep a connect from
     * starting one, as Run again on the import card does.
     */
    public function testConnectingWhileTheCatalogImportHasStalledStartsAFreshOne(): void
    {
        $this->importsOnConnect->start($this->connection(0));
        $stalled = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($stalled);
        $this->jobManager->markRunning($stalled);
        $this->connection->update(
            self::TABLE,
            ['updated_at' => $this->clockDate(-JobManager::STALLED_SECONDS - 1)],
            ['id = ?' => (int)$stalled->getId()]
        );

        self::assertSame(
            [Job::TYPE_CATALOG => true, Job::TYPE_CUSTOMERS => false, Job::TYPE_ORDERS => false],
            $this->importsOnConnect->start($this->connection(0))
        );

        self::assertSame(Job::STATUS_CANCELLED, $this->fetchRow(self::TABLE, (int)$stalled->getId())['status']);
        $fresh = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        self::assertNotNull($fresh);
        self::assertSame(Job::STATUS_PENDING, $fresh->getStatus());
    }

    /**
     * CON-237: a second connection's connect queues its own three imports
     * while the first connection's are queued, and a stalled import of one
     * connection is not cancelled by the other's start.
     */
    public function testEachConnectionQueuesItsOwnImports(): void
    {
        $this->importsOnConnect->start($this->connection(0));
        $this->connection->update(self::TABLE, ['updated_at' => '2000-01-01 00:00:00']);

        self::assertSame(
            [Job::TYPE_CATALOG => true, Job::TYPE_CUSTOMERS => true, Job::TYPE_ORDERS => true],
            $this->importsOnConnect->start($this->connection(2))
        );

        $rows = $this->fetchAll(self::TABLE);
        self::assertSame(['0', '0', '0', '2', '2', '2'], array_map('strval', array_column($rows, 'website_id')));
        self::assertSame(
            array_fill(0, 6, Job::STATUS_PENDING),
            array_column($rows, 'status'),
            'The first connection\'s stalled imports are its own to cancel'
        );
    }

    /**
     * A connection with this key (its settings are not read here).
     */
    private function connection(int $key): Connection
    {
        return new Connection($key, $this->createMock(EngineSettings::class), [max($key, 1)], [1], 1, $key === 0);
    }

    public function testHoldingTheCatalogImportBackBeforeItsFirstRunSendsNothing(): void
    {
        $this->importsOnConnect->start($this->connection(0));

        // Hold back: the Settings > Intelligence endpoint's cancel. The
        // customers and orders imports queued behind it do not start either
        // (CON-367, as the WooCommerce plugin's sequence ends).
        self::assertSame(1, $this->jobManager->cancelEngineSequence(Job::TYPE_CATALOG));

        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::never())->method('process');
        $this->objectManager->create(BackfillTick::class, [
            'jobManager' => $this->jobManager,
            'engineSettings' => $this->createMock(EngineSettings::class),
            'historyImport' => $this->createMock(HistoryImport::class),
            'historyProgress' => $this->createMock(HistoryProgress::class),
            'processors' => ['catalog:engine' => $processor],
        ])->execute();

        self::assertSame([], $this->fetchAll(IngestEventResource::TABLE_NAME));
        self::assertSame(
            [Job::STATUS_CANCELLED, Job::STATUS_CANCELLED, Job::STATUS_CANCELLED],
            array_column($this->fetchAll(self::TABLE), 'status')
        );

        // Started later by hand, it runs as a fresh import.
        self::assertNotNull(
            $this->jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
        );
    }

    /**
     * CON-367, as the WooCommerce plugin's cancel ends its sequence: the
     * imports queued behind a cancelled one do not start, one that already
     * runs is left alone, and another connection's imports are untouched.
     */
    public function testACancelStopsTheImportsQueuedBehindItOnly(): void
    {
        $this->importsOnConnect->start($this->connection(0));
        $this->importsOnConnect->start($this->connection(2));
        $catalog = $this->jobManager->findActive(Job::TYPE_CATALOG, Job::TARGET_ENGINE, 0);
        self::assertNotNull($catalog);
        $this->jobManager->markRunning($catalog);

        // The customers card's cancel: the orders import behind it goes too.
        self::assertSame(1, $this->jobManager->cancelEngineSequence(Job::TYPE_CUSTOMERS, 0));
        self::assertSame([
            Job::TYPE_CATALOG => Job::STATUS_RUNNING,
            Job::TYPE_CUSTOMERS => Job::STATUS_CANCELLED,
            Job::TYPE_ORDERS => Job::STATUS_CANCELLED,
        ], $this->statuses(0));
        self::assertSame([
            Job::TYPE_CATALOG => Job::STATUS_PENDING,
            Job::TYPE_CUSTOMERS => Job::STATUS_PENDING,
            Job::TYPE_ORDERS => Job::STATUS_PENDING,
        ], $this->statuses(2), 'The other connection\'s imports');

        // The catalog card's cancel of the other connection, its customers
        // import already running: the queued orders import goes, the
        // running customers import stays.
        $customers = $this->jobManager->findActive(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, 2);
        self::assertNotNull($customers);
        $this->jobManager->markRunning($customers);
        $this->jobManager->cancelEngineSequence(Job::TYPE_CATALOG, 2);
        self::assertSame([
            Job::TYPE_CATALOG => Job::STATUS_CANCELLED,
            Job::TYPE_CUSTOMERS => Job::STATUS_RUNNING,
            Job::TYPE_ORDERS => Job::STATUS_CANCELLED,
        ], $this->statuses(2));
    }

    /**
     * @return array<string, string> job type => status of the connection's jobs
     */
    private function statuses(int $key): array
    {
        $statuses = [];
        foreach ($this->fetchAll(self::TABLE) as $row) {
            if ((int)$row['website_id'] === $key) {
                $statuses[(string)$row['job_type']] = (string)$row['status'];
            }
        }

        return $statuses;
    }
}
