<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Log;

use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3565: the status the Log shows for a row closed without being
 * delivered. The grid (its UNION) and the drawer (QueueRowLoader) read one
 * derived value, so the pill, the status filter and Details agree.
 */
class LogStatusTest extends IntegrationTestCase
{
    private EventQueue $queue;
    private QueueRowLoader $rowLoader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->rowLoader = $this->objectManager->create(QueueRowLoader::class);
    }

    public function testARowClosedWithoutSendingReadsAsSkipped(): void
    {
        $this->queue->enqueue(EventType::CONTACT_SYNC, ['contact' => ['email' => 'jane@example.com']], 'jane@example.com', 1, 'u-1');
        $claimed = $this->queue->claimBatch();
        $this->queue->markSkipped($claimed[0], 'Skipped: Smaily does not have this contact.');

        self::assertSame(Collection::STATUS_SKIPPED, $this->rowLoader->load('smaily-1')['status'] ?? null);
        self::assertSame(['smaily-1' => Collection::STATUS_SKIPPED], $this->gridStatuses());
    }

    public function testADeliveredRowReadsAsSent(): void
    {
        $this->queue->enqueue(EventType::CONTACT_SYNC, ['contact' => ['email' => 'jane@example.com']], 'jane@example.com', 1, 'u-1');
        $claimed = $this->queue->claimBatch();
        $this->queue->markFailed($claimed[0], 'Connection timed out');
        $this->clock->travel(3600);
        $claimed = $this->queue->claimBatch();
        $this->queue->markSent($claimed[0], '{"email":"jane@example.com"}', '{"code":101}');

        self::assertSame(Event::STATUS_SENT, $this->rowLoader->load('smaily-1')['status'] ?? null);
        self::assertSame(['smaily-1' => Event::STATUS_SENT], $this->gridStatuses());
    }

    public function testAWithdrawnRowStaysWithdrawnAfterAnEarlierFailure(): void
    {
        $this->queue->enqueue(EventType::AUTOMATION_TRIGGER, ['trigger_type' => 'abandoned_cart'], 'jane@example.com', 0, 'u-1');
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['attempts' => 1, 'last_error' => 'Connection timed out'],
            ['id = ?' => 1]
        );
        $this->queue->cancelPendingAutomation('abandoned_cart', 'jane@example.com');

        self::assertSame(Collection::STATUS_WITHDRAWN, $this->rowLoader->load('smaily-1')['status'] ?? null);
        self::assertSame(['smaily-1' => Collection::STATUS_WITHDRAWN], $this->gridStatuses());
    }

    /**
     * @return array<string, string> grid status by log id
     */
    private function gridStatuses(): array
    {
        /** @var Collection $collection */
        $collection = $this->objectManager->create(Collection::class);
        $statuses = [];
        foreach ($collection->getData() as $row) {
            $statuses[(string)$row['log_id']] = (string)$row['status'];
        }

        return $statuses;
    }
}
