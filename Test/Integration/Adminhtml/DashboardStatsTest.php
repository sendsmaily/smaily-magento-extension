<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Smaily\Connect\Model\Adminhtml\DashboardStats;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * The Dashboard's "Queued today" tile against real queue tables (PRO-3628).
 */
class DashboardStatsTest extends IntegrationTestCase
{
    private int $sequence = 0;

    /**
     * The tile's caption is "events waiting to send": it counts the rows
     * queued today that are still waiting — first attempt or a retry — on
     * both queues, and no row that was sent, failed or is being sent now.
     */
    public function testQueuedTodayCountsOnlyTheRowsTodayThatAreStillWaiting(): void
    {
        $today = $this->clockDate();
        $yesterday = gmdate('Y-m-d 23:59:59', $this->clock->now() - 86400);

        $this->smailyRow('pending', $today);
        $this->smailyRow('pending', $today, 2);
        $this->smailyRow('sent', $today);
        $this->smailyRow('failed', $today);
        $this->smailyRow('sending', $today);
        $this->smailyRow('pending', $yesterday);
        $this->ingestRow('pending', $today);
        $this->ingestRow('sent', $today);
        $this->ingestRow('pending', $yesterday);

        self::assertSame(3, $this->objectManager->create(DashboardStats::class)->queuedToday());
    }

    private function smailyRow(string $status, string $createdAt, int $attempts = 0): void
    {
        $this->connection->insert(EventResource::TABLE_NAME, [
            'event_type' => 'contact.sync',
            'entity_id' => 'test@example.com',
            'event_uuid' => 'dash-' . ++$this->sequence,
            'payload' => '{}',
            'status' => $status,
            'attempts' => $attempts,
            'created_at' => $createdAt,
        ]);
    }

    private function ingestRow(string $status, string $createdAt): void
    {
        $this->connection->insert(IngestEventResource::TABLE_NAME, [
            'domain' => 'catalog',
            'entity_id' => '1',
            'event_uuid' => 'dash-' . ++$this->sequence,
            'payload' => '{}',
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }
}
