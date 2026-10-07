<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Health;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Shared queue-health queries: the HealthCheck cron and the admin dashboard
 * read the SAME numbers, so the dashboard verdict can never disagree with
 * the notification the merchant received.
 */
class QueueHealth
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Failed rows across both delivery queues within the last N seconds.
     */
    public function failedSince(int $seconds = 86400): int
    {
        $cutoff = $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() - $seconds);
        $connection = $this->resourceConnection->getConnection();
        $failed = 0;
        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $select = $connection->select()
                ->from($this->resourceConnection->getTableName($table), ['cnt' => 'COUNT(*)'])
                ->where('status = ?', 'failed')
                ->where('updated_at >= ?', $cutoff);
            $failed += (int)$connection->fetchOne($select);
        }

        return $failed;
    }
}
