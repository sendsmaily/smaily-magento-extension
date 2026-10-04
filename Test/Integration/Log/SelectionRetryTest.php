<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Log;

use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Log\SelectionRetry;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\RecordingRowLoader;

/**
 * PRO-2510: the Log's mass Retry over a "Select all" of a large Log works
 * through the selection in batches, and ResendGuard still decides every row.
 */
class SelectionRetryTest extends IntegrationTestCase
{
    private const FAILED_CONTACT_SYNCS = 20000;
    private const FAILED_INGESTS = 500;

    private RecordingRowLoader $rowLoader;
    private SelectionRetry $retry;
    private int $uuid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rowLoader = $this->objectManager->create(RecordingRowLoader::class);
        $this->retry = $this->objectManager->create(SelectionRetry::class, ['rowLoader' => $this->rowLoader]);
    }

    public function testSelectAllRetriesEveryFailedRowTheGuardClearsInBatches(): void
    {
        $this->seedLargeLog();
        $before = $this->refusedAtOnce();
        self::assertSame(['smaily' => [1, 3]], $before, 'superseded #1, erased #3');

        [$retried, $skipped] = $this->retry->retry($this->collection());

        self::assertSame(self::FAILED_CONTACT_SYNCS + self::FAILED_INGESTS, $retried);
        self::assertSame(2, $skipped);
        // Exactly the rows the guard refused over the whole selection at once
        // are still failed; every other failed row is pending again.
        self::assertSame(['1', '3'], $this->idsWithStatus(EventResource::TABLE_NAME, Event::STATUS_FAILED));
        self::assertSame([], $this->idsWithStatus(IngestEventResource::TABLE_NAME, Event::STATUS_FAILED));
        self::assertSame(
            (string)(self::FAILED_CONTACT_SYNCS + 1),
            $this->connection->fetchOne($this->connection->select()
                ->from(EventResource::TABLE_NAME, 'COUNT(*)')
                ->where('status = ?', Event::STATUS_PENDING)
                ->where('attempts = 0'))
        );
        self::assertSame(Event::STATUS_SENT, $this->statusOf(2), 'the delivered row is untouched');
        self::assertSame(Event::STATUS_SENT, $this->statusOf(5), 'the withdrawn row is untouched');

        self::assertCount(22, $this->rowLoader->batches, '21 batches of the marketing queue, 1 of the ingest queue');
        self::assertLessThanOrEqual(
            SelectionRetry::BATCH_SIZE,
            max(array_column($this->rowLoader->batches, 1))
        );
        self::assertSame(
            [self::FAILED_CONTACT_SYNCS + 2, self::FAILED_INGESTS],
            [$this->idsAskedAbout(Collection::SOURCE_SMAILY), $this->idsAskedAbout(Collection::SOURCE_INTELLIGENCE)]
        );
    }

    public function testTheGridsFiltersAndExclusionsNarrowTheRetry(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->insertSmaily([$this->smailyRow(EventType::CONTACT_SYNC, 'c' . $i . '@example.com', 'failed')]);
        }
        $this->insertSmaily([$this->smailyRow(EventType::ENGINE_IDENTITY_MERGE, 'jane@example.com', 'failed')]);
        $this->insertIngest([$this->ingestRow('failed')]);

        $selection = $this->collection();
        $selection->addFieldToFilter('type', ['eq' => EventType::CONTACT_SYNC]);
        $selection->addFieldToFilter('log_id', ['nin' => ['smaily-1']]);

        self::assertSame([2, 0], $this->retry->retry($selection));
        self::assertSame(['1', '4'], $this->idsWithStatus(EventResource::TABLE_NAME, Event::STATUS_FAILED));
        self::assertSame(['1'], $this->idsWithStatus(IngestEventResource::TABLE_NAME, Event::STATUS_FAILED));
    }

    public function testAnEmptySelectionRetriesNothing(): void
    {
        $this->insertSmaily([$this->smailyRow(EventType::CONTACT_SYNC, 'c@example.com', 'pending')]);

        self::assertSame([0, 0], $this->retry->retry($this->collection()));
        self::assertSame([], $this->rowLoader->batches);
    }

    /**
     * #1 a failed welcome trigger a later delivery (#2) superseded, #3 a
     * failed row of an erased contact, #4 a pending row, #5 a withdrawn
     * reminder; then the failed contact syncs and the failed ingest rows.
     */
    private function seedLargeLog(): void
    {
        $welcome = ['trigger_type' => 'welcome'];
        $abandonedCart = ['trigger_type' => 'abandoned_cart'];
        $this->insertEach([
            $this->smailyRow(EventType::AUTOMATION_TRIGGER, 'jane@example.com', 'failed', $welcome),
            ['sent_payload' => '{}', 'last_response' => '{"code":101}']
                + $this->smailyRow(EventType::AUTOMATION_TRIGGER, 'jane@example.com', 'sent', $welcome),
            $this->smailyRow(EventType::CONTACT_SYNC, Erasure::PLACEHOLDER, 'failed'),
            $this->smailyRow(EventType::CONTACT_SYNC, 'pending@example.com', 'pending'),
            ['last_response' => EventQueue::CANCELLED_RESPONSE]
                + $this->smailyRow(EventType::AUTOMATION_TRIGGER, 'joe@example.com', 'sent', $abandonedCart),
        ]);

        $rows = [];
        for ($i = 0; $i < self::FAILED_CONTACT_SYNCS; $i++) {
            $rows[] = $this->smailyRow(EventType::CONTACT_SYNC, 'c' . $i . '@example.com', 'failed');
        }
        $this->insertSmaily($rows);

        $rows = [];
        for ($i = 0; $i < self::FAILED_INGESTS; $i++) {
            $rows[] = $this->ingestRow('failed');
        }
        $this->insertIngest($rows);
    }

    /**
     * What the mass retry refused before PRO-2510: the guard asked once about
     * every failed row of the selection.
     *
     * @return array<string, int[]>
     */
    private function refusedAtOnce(): array
    {
        $guard = $this->objectManager->create(ResendGuard::class);
        $refused = [];
        foreach ([Collection::SOURCE_SMAILY, Collection::SOURCE_INTELLIGENCE] as $source) {
            $ids = [];
            foreach ($this->collection()->getAllIds() as $logId) {
                [$rowSource, $id] = Collection::splitLogId((string)$logId);
                if ($rowSource === $source) {
                    $ids[] = $id;
                }
            }
            $reasons = $guard->refusalReasons($source, $this->rowLoader->loadFailed($source, $ids));
            if ($reasons) {
                $refused[$source] = array_keys($reasons);
                sort($refused[$source]);
            }
        }
        $this->rowLoader->batches = [];

        return $refused;
    }

    private function collection(): Collection
    {
        return $this->objectManager->create(Collection::class);
    }

    /**
     * @return string[]
     */
    private function idsWithStatus(string $table, string $status): array
    {
        return $this->connection->fetchCol(
            $this->connection->select()->from($table, 'id')->where('status = ?', $status)->order('id')
        );
    }

    private function statusOf(int $id): string
    {
        return (string)$this->connection->fetchOne(
            $this->connection->select()->from(EventResource::TABLE_NAME, 'status')->where('id = ?', $id)
        );
    }

    private function idsAskedAbout(string $source): int
    {
        $total = 0;
        foreach ($this->rowLoader->batches as [$batchSource, $count]) {
            $total += $batchSource === $source ? $count : 0;
        }

        return $total;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function smailyRow(string $type, string $entityId, string $status, array $payload = []): array
    {
        return [
            'event_type' => $type,
            'entity_id' => $entityId,
            'event_uuid' => sprintf('00000000-0000-0000-0000-%012d', ++$this->uuid),
            'payload' => json_encode($payload ?: ['email' => $entityId]),
            'status' => $status,
            'attempts' => $status === 'failed' ? 5 : 0,
            'last_error' => $status === 'failed' ? 'boom' : null,
            'sent_payload' => null,
            'last_response' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ingestRow(string $status): array
    {
        return [
            'domain' => 'catalog',
            'entity_id' => (string)++$this->uuid,
            'event_uuid' => sprintf('00000000-0000-0000-0000-%012d', $this->uuid),
            'payload' => '{}',
            'status' => $status,
            'attempts' => 5,
            'last_error' => 'boom',
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertSmaily(array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->connection->insertMultiple(EventResource::TABLE_NAME, $chunk);
        }
    }

    /**
     * Rows whose columns differ in order, one insert each.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertEach(array $rows): void
    {
        foreach ($rows as $row) {
            $this->connection->insert(EventResource::TABLE_NAME, $row);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertIngest(array $rows): void
    {
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->connection->insertMultiple(IngestEventResource::TABLE_NAME, $chunk);
        }
    }
}
