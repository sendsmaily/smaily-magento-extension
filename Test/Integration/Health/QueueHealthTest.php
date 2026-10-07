<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Health;

use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Health\QueueHealth;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3961: the queue health check counts failures by when they happened.
 * A row whose previous change lies outside the window but that failed
 * inside it counts; a row that failed outside it does not.
 */
class QueueHealthTest extends IntegrationTestCase
{
    private const TWO_DAYS = 2 * 86400;

    public function testFailuresCountByWhenTheyHappened(): void
    {
        $queue = $this->objectManager->create(EventQueue::class);
        $ingest = $this->objectManager->create(IngestQueue::class);
        $queue->enqueue(EventType::CONTACT_SYNC, [], 'old@example.com', 1, 'u-old');
        $queue->enqueue(EventType::CONTACT_SYNC, [], 'single@example.com', 1, 'u-single');
        $queue->enqueue(EventType::CONTACT_SYNC, [], 'batch-1@example.com', 1, 'u-batch-1');
        $queue->enqueue(EventType::CONTACT_SYNC, [], 'batch-2@example.com', 1, 'u-batch-2');
        $ingest->enqueue('orders', [], '100', 1, 'ing-1');

        // A row that failed two days ago: outside the 24-hour window.
        $this->clock->travel(-self::TWO_DAYS);
        $old = $queue->claimBatch(1);
        $queue->markFailed($old[0], 'permanent_http_404: gone', terminal: true);
        $this->clock->travel(self::TWO_DAYS);

        // The others were last changed two days ago, then fail now.
        foreach ([EventResource::TABLE_NAME, IngestEventResource::TABLE_NAME] as $table) {
            $this->connection->update(
                $table,
                ['updated_at' => $this->clockDate(-self::TWO_DAYS)],
                ['status = ?' => 'pending']
            );
        }
        $claimed = $queue->claimBatch();
        self::assertCount(3, $claimed);
        $queue->markFailed($claimed[0], 'permanent_http_404: gone', terminal: true);
        $queue->markFailedMany([$claimed[1], $claimed[2]], 'permanent_http_400: refused', terminal: true);
        $ingest->markFailed($ingest->claimBatch('orders', 100)[0], 'invalid', true);

        self::assertSame(4, $this->objectManager->create(QueueHealth::class)->failedSince(86400));
    }
}
