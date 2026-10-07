<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Log;

use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Log\AttemptHistory;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3961: Details dates the latest attempt at the time it happened, read
 * from the row as the queue stored it — not at the row's previous change.
 */
class AttemptHistoryTest extends IntegrationTestCase
{
    private EventQueue $queue;
    private QueueRowLoader $rowLoader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->rowLoader = $this->objectManager->create(QueueRowLoader::class);
    }

    public function testTheLatestFailureAndTheDeliveryCarryTheTimeTheyHappened(): void
    {
        $this->queue->enqueue(EventType::CONTACT_SYNC, [], 'jane@example.com', 1, 'u-1');
        $this->pinUpdatedAt(EventResource::TABLE_NAME, $this->clockDate(-7200));

        $this->clock->travel(600);
        $failedAt = $this->clockDate();
        $this->queue->markFailed($this->queue->claimBatch()[0], 'Connection timed out');

        self::assertSame(
            [AttemptHistory::FAILED, $failedAt, true],
            $this->entryOf('smaily-1', AttemptHistory::FAILED)
        );

        $this->clock->travel(EventQueue::BACKOFF_SECONDS[0] + 1);
        $deliveredAt = $this->clockDate();
        $this->queue->markSent($this->queue->claimBatch()[0], '[]', '{"code":101}');

        self::assertSame(
            [AttemptHistory::DELIVERED, $deliveredAt, false],
            $this->entryOf('smaily-1', AttemptHistory::DELIVERED)
        );
    }

    public function testABatchedFailureCarriesTheTimeItHappened(): void
    {
        $this->queue->enqueue(EventType::CONTACT_SYNC, [], 'jane@example.com', 1, 'u-1');
        $this->queue->enqueue(EventType::CONTACT_SYNC, [], 'john@example.com', 1, 'u-2');
        $this->pinUpdatedAt(EventResource::TABLE_NAME, $this->clockDate(-7200));

        $this->clock->travel(600);
        $this->queue->markFailedMany($this->queue->claimBatch(), 'HTTP 503');

        foreach (['smaily-1', 'smaily-2'] as $logId) {
            self::assertSame(
                [AttemptHistory::FAILED, $this->clockDate(), true],
                $this->entryOf($logId, AttemptHistory::FAILED),
                $logId
            );
        }
    }

    public function testASkipCarriesTheTimeItHappened(): void
    {
        $this->queue->enqueue(EventType::CONTACT_SYNC, [], 'jane@example.com', 1, 'u-1');
        $this->pinUpdatedAt(EventResource::TABLE_NAME, $this->clockDate(-7200));

        $this->clock->travel(600);
        $this->queue->markSkipped($this->queue->claimBatch()[0], 'Skipped: Smaily does not have this contact.');

        self::assertSame(
            [AttemptHistory::SKIPPED, $this->clockDate(), false],
            $this->entryOf('smaily-1', AttemptHistory::SKIPPED)
        );
    }

    public function testAnIngestRowsFailureCarriesTheTimeItHappened(): void
    {
        $ingest = $this->objectManager->create(IngestQueue::class);
        $ingest->enqueue('catalog', [], 'SKU-1', 1, 'ing-1');
        $this->pinUpdatedAt(IngestEventResource::TABLE_NAME, $this->clockDate(-7200));

        $this->clock->travel(600);
        $ingest->markFailed($ingest->claimBatch('catalog', 100)[0], 'HTTP 503');

        self::assertSame(
            [AttemptHistory::FAILED, $this->clockDate(), true],
            $this->entryOf('intelligence-1', AttemptHistory::FAILED)
        );
    }

    /**
     * The last Details entry of $result: its result, time and whether it
     * carries the row's last error.
     *
     * @return array{0: string, 1: string|null, 2: bool}
     */
    private function entryOf(string $logId, string $result): array
    {
        $row = $this->rowLoader->load($logId);
        self::assertIsArray($row);
        $entries = array_values(array_filter(
            (new AttemptHistory())->entries($row),
            static fn (array $entry): bool => $entry['result'] === $result
        ));
        self::assertNotEmpty($entries, sprintf('%s has a %s entry', $logId, $result));
        $entry = end($entries);

        return [$entry['result'], $entry['at'], $entry['latest_error']];
    }

    /**
     * Date every row of $table at $at, as an earlier change would have left it.
     */
    private function pinUpdatedAt(string $table, string $at): void
    {
        $this->connection->update($table, ['updated_at' => $at]);
    }
}
