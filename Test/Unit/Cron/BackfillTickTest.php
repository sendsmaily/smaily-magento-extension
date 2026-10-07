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
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The engine-bound imports (catalog / customers / orders) share this router,
 * so the "the account is not active" gate lives here once (PRO-2451).
 */
class BackfillTickTest extends TestCase
{
    public function testARefusedAccountStopsANeverStartedEngineImportAtZero(): void
    {
        $job = $this->createJob(Job::TARGET_ENGINE, null);
        $job->expects(self::once())->method('setData')->with('total_count', 0);
        $jobManager = $this->createJobManager($job);
        $jobManager->expects(self::once())->method('complete')->with($job);
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
        $jobManager->expects(self::once())->method('cancel')->with($job);
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

    private function createJob(string $target, ?int $totalCount): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(3);
        $job->method('getJobType')->willReturn($target === Job::TARGET_ENGINE ? 'catalog' : 'contacts');
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
        array $processors
    ): BackfillTick {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isRefused')->willReturn($refused);

        return new BackfillTick($jobManager, $settings, $this->createMock(Logger::class), $processors);
    }
}
