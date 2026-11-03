<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The Campaign Intelligence connection an engine import sends to
 * (CON-237): its job's website_id holds the connection's key (0, the
 * primary — Job::ENGINE_WEBSITE_ID — for every job before CON-237). The
 * catalog, customers and orders processors read it here.
 *
 * A job whose connection is gone (its website connection was
 * disconnected) or whose engine store refused it is stopped the way the
 * tick stops an engine job when the primary is refused
 * (Cron\BackfillTick::stopEngineJob(), which still answers for the
 * primary): one that never started completes having queued nothing, one
 * caught mid-import is cancelled.
 */
class EngineJobConnection
{
    public function __construct(
        private readonly Connections $connections,
        private readonly JobManager $jobManager,
        private readonly Logger $logger
    ) {
    }

    /**
     * The job's connection, or null when the job is stopped.
     */
    public function of(Job $job): ?Connection
    {
        $connection = $this->connections->forKey($job->getWebsiteId());
        if ($connection !== null && ($connection->isPrimary() || !$connection->settings()->isRefused())) {
            return $connection;
        }

        if ($job->getData('total_count') === null) {
            $job->setData('total_count', 0);
            $this->jobManager->complete($job);
        } else {
            $this->jobManager->cancel($job);
        }
        $this->logger->info('Backfill job stopped: its Campaign Intelligence store is not connected or not active', [
            'job_id' => $job->getId(),
            'connection' => $job->getWebsiteId(),
        ]);

        return null;
    }
}
