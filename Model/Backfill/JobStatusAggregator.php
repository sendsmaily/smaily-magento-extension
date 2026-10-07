<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * Collapses the per-website job statuses of a backfill run into one
 * aggregate status for the Backfill card.
 *
 * `running` wins whenever real progress is happening somewhere. A set that
 * is entirely `pending` — queued, but the cron tick (`BackfillTick`) has not
 * picked any of it up yet, so `JobManager::markRunning()` never fired — is
 * reported as `pending` rather than `running`, so the UI can show honest
 * "queued" copy instead of a bare "Importing… 0 / ?" that implies work is
 * already underway (PRO-1397 finding #8).
 */
class JobStatusAggregator
{
    /**
     * @param string[] $statuses one Job::STATUS_* value per still-open job row
     */
    public function resolve(array $statuses): string
    {
        if (!$statuses) {
            return 'idle';
        }
        if (in_array(Job::STATUS_RUNNING, $statuses, true)) {
            return 'running';
        }
        if (in_array(Job::STATUS_PENDING, $statuses, true)) {
            return 'pending';
        }
        if (in_array(Job::STATUS_FAILED, $statuses, true)) {
            return 'failed';
        }
        if (in_array(Job::STATUS_CANCELLED, $statuses, true)) {
            return 'cancelled';
        }

        return 'completed';
    }
}
