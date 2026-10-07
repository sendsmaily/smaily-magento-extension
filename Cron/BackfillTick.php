<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\ProcessorInterface;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * Advances the oldest active backfill job one chunk per cron run.
 * Processors are registered per "{job_type}:{target}" key via di.xml.
 */
class BackfillTick
{
    /**
     * @param array<string, ProcessorInterface> $processors
     */
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly EngineSettings $engineSettings,
        private readonly Logger $logger,
        private readonly array $processors = []
    ) {
    }

    public function execute(): void
    {
        $job = $this->jobManager->nextActive();
        if ($job === null) {
            return;
        }

        $key = sprintf('%s:%s', $job->getJobType(), $job->getTarget());

        if ($job->getTarget() === Job::TARGET_ENGINE && $this->engineSettings->isRefused()) {
            $this->stopEngineJob($job, $key);

            return;
        }

        $processor = $this->processors[$key] ?? null;
        if (!$processor instanceof ProcessorInterface) {
            $this->jobManager->fail($job, sprintf('No backfill processor registered for "%s"', $key));

            return;
        }

        try {
            $processor->process($job);
        } catch (\Throwable $exception) {
            $this->jobManager->fail($job, $exception->getMessage());
            $this->logger->error('Backfill job failed', [
                'job_id' => $job->getId(),
                'type' => $key,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($job->getStatus() === Job::STATUS_COMPLETED) {
            $this->logger->info('Backfill job completed', [
                'job_id' => $job->getId(),
                'type' => $key,
                'processed' => $job->getProcessedCount(),
                'failed' => $job->getFailedCount(),
            ]);
        }
    }

    /**
     * The one gate every engine-bound import passes when the account has been
     * refused (contract §2) — catalog / customers / orders share this router,
     * so the gate lives here rather than three times over.
     * A job that never started completes having queued nothing, its total 0
     * rather than a count promising a sync that will not happen; one caught
     * mid-import is stopped at this page boundary like an admin cancel,
     * keeping the total and the progress it really achieved — the same
     * treatment the contacts import gets when its switch goes off (PRO-1764).
     */
    private function stopEngineJob(Job $job, string $key): void
    {
        if ($job->getData('total_count') === null) {
            $job->setData('total_count', 0);
            $this->jobManager->complete($job);
        } else {
            $this->jobManager->cancel($job);
        }

        $this->logger->info('Backfill job stopped: the Campaign Intelligence account is not active', [
            'job_id' => $job->getId(),
            'type' => $key,
        ]);
    }
}
