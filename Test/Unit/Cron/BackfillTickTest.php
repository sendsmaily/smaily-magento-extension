<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\BackfillTick;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The engine-bound imports (catalog / customers / orders) share this router,
 * so the "the account is not active" gate lives here once (PRO-2451). Every
 * import stores the reason it stopped here (CON-233).
 */
class BackfillTickTest extends TestCase
{
    public function testARefusedAccountStopsANeverStartedEngineImportAtZero(): void
    {
        $job = $this->createJob(Job::TARGET_ENGINE, null);
        $job->expects(self::once())->method('setData')->with('total_count', 0);
        $jobManager = $this->createJobManager($job);
        $jobManager->expects(self::once())->method('complete')->with($job, Job::REASON_ENGINE_NOT_ACTIVE);
        $jobManager->expects(self::never())->method('cancel');
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::never())->method('process');

        $this->createTick($jobManager, true, ['catalog:engine' => $processor])->execute();
    }

    public function testARefusedAccountStopsAnInFlightEngineImportKeepingItsProgress(): void
    {
        $job = $this->createJob(Job::TARGET_ENGINE, 412);
        $job->expects(self::never())->method('setData');
        $jobManager = $this->createJobManager($job);
        $jobManager->expects(self::once())->method('cancel')->with($job, Job::REASON_ENGINE_NOT_ACTIVE);
        $jobManager->expects(self::never())->method('complete');

        $this->createTick($jobManager, true, [])->execute();
    }

    public function testTheSmailyContactImportIsUnaffectedByARefusedAccount(): void
    {
        $job = $this->createJob(Job::TARGET_SMAILY, null);
        $jobManager = $this->createJobManager($job);
        $jobManager->expects(self::never())->method('cancel');
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::once())->method('process')->with($job);

        $this->createTick($jobManager, true, ['contacts:smaily' => $processor])->execute();
    }

    public function testAnActiveAccountRunsTheEngineImportAsBefore(): void
    {
        $job = $this->createJob(Job::TARGET_ENGINE, null);
        $jobManager = $this->createJobManager($job);
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::once())->method('process')->with($job);

        $this->createTick($jobManager, false, ['catalog:engine' => $processor])->execute();
    }

    /**
     * CON-241: before a Campaign Intelligence import runs, a queued orders
     * import begins its history import, and the progress report runs every
     * minute — also when no import is left, since `complete` follows the
     * last delivery. A contacts import begins nothing.
     */
    public function testAnEngineImportBeginsTheHistoryImportAndEveryRunReports(): void
    {
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->expects(self::once())->method('beginQueued');
        $historyProgress = $this->createMock(HistoryProgress::class);
        $historyProgress->expects(self::once())->method('report');
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::once())->method('process');
        $this->createTick(
            $this->createJobManager($this->createJob(Job::TARGET_ENGINE, null)),
            false,
            ['catalog:engine' => $processor],
            $historyImport,
            $historyProgress
        )->execute();

        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->expects(self::never())->method('beginQueued');
        $historyProgress = $this->createMock(HistoryProgress::class);
        $historyProgress->expects(self::once())->method('report');
        $this->createTick(
            $this->createJobManager($this->createJob(Job::TARGET_SMAILY, null)),
            false,
            ['contacts:smaily' => $this->createMock(ProcessorInterface::class)],
            $historyImport,
            $historyProgress
        )->execute();

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('nextActive')->willReturn(null);
        $historyProgress = $this->createMock(HistoryProgress::class);
        $historyProgress->expects(self::once())->method('report');
        $this->createTick($jobManager, false, [], null, $historyProgress)->execute();
    }

    /**
     * A history import that cannot begin is logged; the import the tick
     * took up still runs.
     */
    public function testAHistoryImportThatCannotBeginLeavesTheImportRunning(): void
    {
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('beginQueued')->willThrowException(new \RuntimeException('database gone'));
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->expects(self::once())->method('process');

        $this->createTick(
            $this->createJobManager($this->createJob(Job::TARGET_ENGINE, null)),
            false,
            ['catalog:engine' => $processor],
            $historyImport
        )->execute();
    }

    /**
     * CON-233: an import that stops stores where the error happened, never
     * its message, which can quote a customer's data — the import card
     * shows what is stored. Every import passes this router. CON-357: the
     * log names the error's type, code and place, never its text either — a
     * database error quotes the row it refused.
     *
     * @dataProvider imports
     */
    public function testAStoppedImportStoresWhereTheErrorHappenedNotItsMessage(string $jobType, string $target): void
    {
        $job = $this->createJob($target, null, $jobType);
        $jobManager = $this->createJobManager($job);
        $stored = null;
        $jobManager->expects(self::once())->method('fail')
            ->willReturnCallback(function (Job $failed, string $error) use (&$stored): void {
                $stored = $error;
            });
        $processor = $this->createMock(ProcessorInterface::class);
        $processor->method('process')->willThrowException(new \PDOException(
            "SQLSTATE[23000]: Duplicate entry 'mari@example.com' for key 'email' (Mari Maasikas)",
            23000
        ));
        $logged = [];
        $logger = $this->createMock(Logger::class);
        $logger->method('error')->willReturnCallback(
            static function (string $message, array $context = []) use (&$logged): void {
                $logged[] = [$message, $context];
            }
        );

        $this->createTick($jobManager, false, [$jobType . ':' . $target => $processor], null, null, $logger)
            ->execute();

        self::assertMatchesRegularExpression('/^PDOException at BackfillTickTest\.php:\d+$/', $stored);
        self::assertMatchesRegularExpression(
            '/^PDOException at BackfillTickTest\.php:\d+ \(code 23000\)$/',
            $logged[0][1]['error'] ?? ''
        );
        $kept = (string)$stored . json_encode($logged);
        self::assertStringNotContainsString('mari@example.com', $kept);
        self::assertStringNotContainsString('Maasikas', $kept);
        self::assertStringNotContainsString('Duplicate entry', $kept);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function imports(): array
    {
        return [
            'catalog' => [Job::TYPE_CATALOG, Job::TARGET_ENGINE],
            'customers' => [Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE],
            'orders' => [Job::TYPE_ORDERS, Job::TARGET_ENGINE],
            'contacts' => [Job::TYPE_CONTACTS, Job::TARGET_SMAILY],
        ];
    }

    private function createJob(string $target, ?int $totalCount, ?string $jobType = null): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(3);
        $job->method('getJobType')->willReturn($jobType ?? ($target === Job::TARGET_ENGINE ? 'catalog' : 'contacts'));
        $job->method('getTarget')->willReturn($target);
        $job->method('getData')->willReturn($totalCount);
        $job->method('getStatus')->willReturn(Job::STATUS_RUNNING);

        return $job;
    }

    private function createJobManager(Job&MockObject $job): JobManager&MockObject
    {
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('nextActive')->willReturn($job);

        return $jobManager;
    }

    /**
     * @param array<string, ProcessorInterface> $processors
     */
    private function createTick(
        JobManager&MockObject $jobManager,
        bool $refused,
        array $processors,
        ?HistoryImport $historyImport = null,
        ?HistoryProgress $historyProgress = null,
        ?Logger $logger = null
    ): BackfillTick {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isRefused')->willReturn($refused);

        return new BackfillTick(
            $jobManager,
            $settings,
            $logger ?? $this->createMock(Logger::class),
            $historyImport ?? $this->createMock(HistoryImport::class),
            $historyProgress ?? $this->createMock(HistoryProgress::class),
            $processors
        );
    }
}
