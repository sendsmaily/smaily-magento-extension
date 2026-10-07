<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * Advances a backfill job one chunk per cron tick. Implementations are
 * registered in Cron\BackfillTick's processor pool via di.xml, keyed by
 * "{job_type}:{target}".
 */
interface ProcessorInterface
{
    /**
     * Process one time-budgeted chunk of the job. The implementation is
     * responsible for recording progress and completing/failing the job
     * through JobManager.
     */
    public function process(Job $job): void;
}
