<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Queue;

use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * IngestQueue semantics against a real smaily_ingest_queue table:
 * per-domain claiming, terminal failures, the pending counter and the
 * event_id stamping used by the engine wire format.
 */
class IngestQueueTest extends IntegrationTestCase
{
    private IngestQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(IngestQueue::class);
    }

    public function testEnqueueIsIdempotentPerEventUuid(): void
    {
        self::assertTrue($this->queue->enqueue('catalog', ['sku' => 'A'], 'A', 1, 'ing-1'));
        self::assertFalse($this->queue->enqueue('catalog', ['sku' => 'B'], 'B', 1, 'ing-1'));

        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame('catalog', $rows[0]['domain']);
        self::assertSame('1', (string)$rows[0]['store_id']);
        self::assertSame(['sku' => 'A'], json_decode((string)$rows[0]['payload'], true));
    }

    public function testClaimBatchIsScopedToOneDomain(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-cat');
        $this->queue->enqueue('orders', [], null, null, 'ing-ord');

        $claimed = $this->queue->claimBatch('catalog', 100);

        self::assertCount(1, $claimed);
        self::assertSame('ing-cat', $claimed[0]->getEventUuid());

        $rows = array_column($this->fetchAll(IngestEventResource::TABLE_NAME), null, 'event_uuid');
        self::assertSame(IngestEvent::STATUS_SENDING, $rows['ing-cat']['status']);
        self::assertSame(IngestEvent::STATUS_PENDING, $rows['ing-ord']['status'], 'Other domains stay untouched');
    }

    public function testMarkFailedTerminalParksImmediately(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-term');
        $claimed = $this->queue->claimBatch('catalog', 100);

        $this->queue->markFailed($claimed[0], 'price: must be a number', true);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status'], 'Validation errors must not be retried');
        self::assertSame('1', (string)$row['attempts']);
        self::assertNull($row['next_retry_at']);
    }

    public function testMarkFailedReschedulesWithSharedBackoffPolicy(): void
    {
        $this->queue->enqueue('browse', [], null, null, 'ing-retry');
        $claimed = $this->queue->claimBatch('browse', 100);

        $this->queue->markFailed($claimed[0], 'HTTP 503');

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame(
            $this->clockDate(IngestQueue::BACKOFF_SECONDS[0]),
            $row['next_retry_at'],
            'First retry follows the shared cross-platform 60s backoff'
        );
    }

    public function testCountPendingCoversPendingAndSendingRowsOfDomain(): void
    {
        $this->queue->enqueue('customers', [], null, null, 'ing-p1');
        $this->queue->enqueue('customers', [], null, null, 'ing-p2');
        $this->queue->enqueue('orders', [], null, null, 'ing-other');
        $this->queue->claimBatch('customers', 1);

        self::assertSame(2, $this->queue->countPending('customers'));
        self::assertSame(1, $this->queue->countPending('orders'));
        self::assertSame(0, $this->queue->countPending('catalog'));
    }

    public function testDecodePayloadStampsWireEventIdFromRowUuid(): void
    {
        $this->queue->enqueue('orders', ['order' => ['id' => '7']], '7', null, 'ing-wire');
        $claimed = $this->queue->claimBatch('orders', 100);

        $payload = $this->queue->decodePayload($claimed[0]);

        self::assertSame('ing-wire', $payload['event_id'], 'event_uuid doubles as the wire event_id');
        self::assertSame(['id' => '7'], $payload['order']);
    }

    public function testRetryResetsFailedRowsForManualRedelivery(): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-parked');
        $claimed = $this->queue->claimBatch('catalog', 100);
        $this->queue->markFailed($claimed[0], 'bad payload', true);

        $id = (int)$this->fetchAll(IngestEventResource::TABLE_NAME)[0]['id'];
        self::assertSame(1, $this->queue->retry([$id]));

        $row = $this->fetchRow(IngestEventResource::TABLE_NAME, $id);
        self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
        self::assertSame('0', (string)$row['attempts']);
    }

    /**
     * PRO-1967: a bulk stock write queues all its rows with one INSERT, each
     * with its own uuid (the wire event_id) and the pending defaults.
     */
    public function testEnqueueManyQueuesEveryRowPendingWithItsOwnUuid(): void
    {
        self::assertSame(2, $this->queue->enqueueMany('catalog', [
            ['payload' => ['sku' => 'A'], 'entity_id' => '1', 'store_id' => 1],
            ['payload' => [], 'entity_id' => '2', 'store_id' => null],
        ]));
        self::assertSame(0, $this->queue->enqueueMany('catalog', []));

        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertCount(2, $rows);
        self::assertSame(['1', '2'], array_column($rows, 'entity_id'));
        self::assertSame(['sku' => 'A'], json_decode((string)$rows[0]['payload'], true));
        self::assertSame('[]', $rows[1]['payload']);
        self::assertSame('1', (string)$rows[0]['store_id']);
        self::assertNull($rows[1]['store_id']);
        self::assertSame([IngestEvent::STATUS_PENDING, IngestEvent::STATUS_PENDING], array_column($rows, 'status'));
        self::assertNotSame($rows[0]['event_uuid'], $rows[1]['event_uuid']);
        self::assertSame(36, strlen((string)$rows[0]['event_uuid']));
    }

    /**
     * PRO-1967: the newest row per entity that has not been delivered yet —
     * pending or being sent; a delivered or failed row, another domain or
     * another entity never counts.
     */
    public function testLatestUndeliveredPayloadsReturnsTheNewestUnsentRowPerEntity(): void
    {
        $this->queue->enqueue('catalog', ['v' => 1], '1', null, 'u-old');
        $this->queue->enqueue('catalog', ['v' => 2], '1', null, 'u-new');
        $this->queue->enqueue('catalog', ['v' => 3], '2', null, 'u-sent');
        $this->queue->enqueue('catalog', ['v' => 4], '3', null, 'u-failed');
        $this->queue->enqueue('orders', ['v' => 5], '4', null, 'u-orders');
        $this->queue->enqueue('catalog', ['v' => 6], '5', null, 'u-sending');
        $this->queue->enqueue('catalog', ['v' => 7], '9', null, 'u-other');
        $claimed = array_column(array_map(
            static fn (IngestEvent $event): array => [$event->getEventUuid(), $event],
            $this->queue->claimBatch('catalog', 100)
        ), 1, 0);
        $this->queue->markSent($claimed['u-sent']);
        $this->queue->markFailed($claimed['u-failed'], 'bad', true);
        $this->queue->release([$claimed['u-old'], $claimed['u-new'], $claimed['u-other']]);

        self::assertSame(
            ['1' => '{"v":2}', '5' => '{"v":6}'],
            $this->queue->latestUndeliveredPayloads('catalog', ['1', '2', '3', '4', '5'])
        );
        self::assertSame([], $this->queue->latestUndeliveredPayloads('catalog', []));
    }

    /**
     * PRO-1967: a product save queues its row and also reaches the stock
     * hooks; the row their marker builds is the same row, still waiting to be
     * sent, so it is not queued a second time. A different row is, and so is
     * a row whose only unsent match is older than the entity's newest.
     */
    public function testEnqueueChangedPayloadsLeavesOutARowIdenticalToTheNewestUnsentRow(): void
    {
        $this->queue->enqueue('catalog', ['sku' => 'SKU-8'], '8', 1, 'c-8');
        $this->queue->enqueue('catalog', ['sku' => 'OLD'], '9', 1, 'c-9');
        $this->queue->enqueue('catalog', ['sku' => 'SKU-7'], '7', 1, 'c-7-old');
        $this->queue->enqueue('catalog', ['sku' => 'NEWER'], '7', 1, 'c-7-new');

        self::assertSame(2, $this->queue->enqueueChangedPayloads('catalog', [
            '7' => ['sku' => 'SKU-7'],
            '8' => ['sku' => 'SKU-8'],
            '9' => ['sku' => 'SKU-9'],
        ], 1));
        self::assertSame(0, $this->queue->enqueueChangedPayloads('catalog', [], 1));

        $queued = array_slice($this->fetchAll(IngestEventResource::TABLE_NAME), 4);
        self::assertSame(['7', '9'], array_column($queued, 'entity_id'));
        self::assertSame(['{"sku":"SKU-7"}', '{"sku":"SKU-9"}'], array_column($queued, 'payload'));
        self::assertSame(['1', '1'], array_map('strval', array_column($queued, 'store_id')));
    }

    public function testDeleteRemovesExactlyTheGivenRows(): void
    {
        $this->queue->enqueue('catalog_changed', [], '1', null, 'd-1');
        $this->queue->enqueue('catalog_changed', [], '2', null, 'd-2');
        $this->queue->enqueue('catalog_changed', [], '3', null, 'd-3');
        $claimed = $this->queue->claimBatch('catalog_changed', 2);

        $this->queue->delete($claimed);
        $this->queue->delete([]);

        self::assertSame(['d-3'], array_column($this->fetchAll(IngestEventResource::TABLE_NAME), 'event_uuid'));
    }

    /**
     * PRO-3961: an outcome dates the row at the time it happened and
     * releases the claim, as in the marketing queue. The row model holds
     * `updated_at` as the claim read it; saving that back would leave the
     * row dated at its previous change.
     *
     * @param \Closure(IngestQueue, IngestEvent): void $outcome
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function testAnOutcomeDatesTheRowAtTheTimeItHappened(\Closure $outcome): void
    {
        $this->queue->enqueue('catalog', [], null, null, 'ing-dated');
        $this->connection->update(
            IngestEventResource::TABLE_NAME,
            ['updated_at' => $this->clockDate(-7200)],
            ['event_uuid = ?' => 'ing-dated']
        );
        $claimed = $this->queue->claimBatch('catalog', 100);
        self::assertCount(1, $claimed);

        $this->clock->travel(600);
        $outcome($this->queue, $claimed[0]);

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame($this->clockDate(), $row['updated_at']);
        self::assertNull($row['claim_token']);
        self::assertNull($row['claimed_at']);
    }

    /**
     * PRO-3962 with PRO-3961: a failure written for many rows at once dates
     * each row at the time it happened and releases its claim, as a
     * failure written for one row does.
     */
    public function testABatchedFailureDatesEachRowAtTheTimeItHappened(): void
    {
        foreach (['ing-b1', 'ing-b2', 'ing-b3'] as $uuid) {
            $this->queue->enqueue('catalog', [], null, null, $uuid);
        }
        $this->connection->update(
            IngestEventResource::TABLE_NAME,
            ['updated_at' => $this->clockDate(-7200)],
            ['event_uuid IN (?)' => ['ing-b1', 'ing-b2', 'ing-b3']]
        );
        $claimed = $this->queue->claimBatch('catalog', 100);
        self::assertCount(3, $claimed);

        $this->clock->travel(600);
        $this->queue->markFailedMany($claimed, 'HTTP 503');

        foreach ($this->fetchAll(IngestEventResource::TABLE_NAME) as $row) {
            self::assertSame($this->clockDate(), $row['updated_at'], $row['event_uuid']);
            self::assertNull($row['claim_token'], $row['event_uuid']);
            self::assertNull($row['claimed_at'], $row['event_uuid']);
            self::assertSame(IngestEvent::STATUS_PENDING, $row['status']);
            self::assertSame('1', (string)$row['attempts']);
        }
    }

    /**
     * @return array<string, array{\Closure(IngestQueue, IngestEvent): void}>
     */
    public static function outcomes(): array
    {
        return [
            'failure' => [static fn (IngestQueue $queue, IngestEvent $event) => $queue->markFailed($event, 'HTTP 503')],
            'parking failure' => [
                static fn (IngestQueue $queue, IngestEvent $event) => $queue->markFailed($event, 'invalid', true),
            ],
            'delivery' => [static fn (IngestQueue $queue, IngestEvent $event) => $queue->markSent($event, '{}')],
        ];
    }
}
