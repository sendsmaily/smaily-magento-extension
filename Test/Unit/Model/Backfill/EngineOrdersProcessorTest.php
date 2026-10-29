<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-3950: the job is taken up before the order count, so a worker that
 * dies counting leaves a running job the tick can set aside, not a queued
 * one that blocks the imports behind it. CON-241: the orders go newest
 * first, the start part before the rest, each row tagged with its job.
 */
class EngineOrdersProcessorTest extends TestCase
{
    private const HISTORY = [
        'import_id' => 'mag-2026-10-28-5',
        'orders_job_id' => 5,
        'start_months' => 18,
        'horizon_months' => 60,
        'order' => 'newest_first',
        'until' => '2026-10-28 12:00:00',
        'start_from' => '2025-04-28 12:00:00',
        'horizon' => '2021-10-28 12:00:00',
        'start_expected' => 2,
        'orders_expected' => 3,
        'buyers_expected' => 3,
        'start_part_queued_to' => null,
        'start_part_done' => false,
        'reported_phase' => null,
        'reported_at' => 0,
        'failed_at' => 0,
    ];

    public function testTheJobIsTakenUpBeforeTheOrderCount(): void
    {
        $job = $this->job(null);
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('markRunning')->with($job);
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('begin')->willThrowException(new \RuntimeException('count died'));

        $this->expectExceptionMessage('count died');
        $this->processor($jobManager, $historyImport, $this->createMock(OrderPayloadBuilder::class), $this->queue())
            ->process($job);
    }

    /**
     * The start part first, newest first, then — from the same cursor — the
     * older orders down to the horizon; the end of the start part is the
     * job's newest queue row then. The total is the orders in the window.
     */
    public function testTheStartPartGoesFirstThenTheOlderOrdersFromTheSameCursor(): void
    {
        $job = $this->job(null);
        $job->expects(self::once())->method('setData')->with('total_count', 3);
        $calls = [];
        $historyImport = $this->historyImport([
            [['entity_id' => '13', 'created_at' => '2026-10-01 10:00:00'],
                ['entity_id' => '12', 'created_at' => '2026-01-01 10:00:00']],
            [],
            [['entity_id' => '11', 'created_at' => '2022-01-01 10:00:00']],
            [],
        ], $calls);
        $historyImport->expects(self::once())->method('update')->with(['start_part_queued_to' => 902])
            ->willReturn(['start_part_queued_to' => 902] + self::HISTORY);
        $queue = $this->queue();
        $queue->method('lastRowId')->with(5)->willReturn(902);
        $enqueued = [];
        $queue->method('enqueue')->willReturnCallback(
            static function (string $domain, array $item, ?string $entityId, $storeId, $uuid, ?int $jobId) use (&$enqueued): bool {
                $enqueued[] = [$item['external_order_id'], $jobId];

                return true;
            }
        );
        $cursors = [];
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->method('recordProgress')->willReturnCallback(
            static function (Job $job, int $processed, int $failed, ?string $cursor) use (&$cursors): void {
                $cursors[] = [$processed, $cursor];
            }
        );
        $jobManager->expects(self::once())->method('complete')->with($job);

        $this->processor($jobManager, $historyImport, $this->builder(), $queue, [11, 12, 13])->process($job);

        self::assertSame([['13', 5], ['12', 5], ['11', 5]], $enqueued);
        self::assertSame([[2, '2026-01-01 10:00:00|12'], [1, '2022-01-01 10:00:00|11']], $cursors);
        self::assertSame([
            ['2025-04-28 12:00:00', ''],
            ['2025-04-28 12:00:00', ''],
            ['2021-10-28 12:00:00', ''],
            ['2021-10-28 12:00:00', ''],
        ], $calls, 'The windows read (the job mock keeps no cursor): the start part, then down to the horizon');
    }

    /**
     * CON-227: an order whose row Magento cannot read is skipped, logged by
     * its id and counted as skipped; the orders after it still go, and the
     * job moves past it.
     *
     * @dataProvider unreadableRows
     */
    public function testAnOrderMagentoCannotReadIsSkippedAndTheImportGoesOn(\Throwable $unreadable): void
    {
        $builder = $this->createMock(OrderPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static function (Order $order) use ($unreadable): array {
                if ((int)$order->getEntityId() === 12) {
                    throw $unreadable;
                }

                return ['external_order_id' => (string)$order->getEntityId()];
            }
        );

        $enqueued = [];
        $ingestQueue = $this->queue();
        $ingestQueue->method('enqueue')->willReturnCallback(
            static function (string $domain, array $item) use (&$enqueued): bool {
                $enqueued[] = [$domain, $item['external_order_id']];

                return true;
            }
        );

        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error')->with(
            'Campaign Intelligence import skipped a row Magento could not read',
            self::callback(static fn (array $context): bool => $context['job_id'] === 5
                && $context['type'] === Job::TYPE_ORDERS
                && $context['entity_id'] === 12
                && str_starts_with($context['error'], get_class($unreadable) . ' at '))
        );

        $job = $this->job(3);
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with($job, 2, 1, '2026-01-01 10:00:00|11');
        $calls = [];
        $historyImport = $this->historyImport([[
            ['entity_id' => '13', 'created_at' => '2026-03-01 10:00:00'],
            ['entity_id' => '12', 'created_at' => '2026-02-01 10:00:00'],
            ['entity_id' => '11', 'created_at' => '2026-01-01 10:00:00'],
        ]], $calls);
        $jobManager->method('isCancelled')->willReturn(true);

        $this->processor($jobManager, $historyImport, $builder, $ingestQueue, [11, 12, 13], new SkippedRow($logger))
            ->process($job);

        self::assertSame([[Client::DOMAIN_ORDERS, '13'], [Client::DOMAIN_ORDERS, '11']], $enqueued);
    }

    /**
     * @return array<string, array{\Throwable}>
     */
    public static function unreadableRows(): array
    {
        return [
            'a stored value Magento cannot unserialize' => [new \InvalidArgumentException(
                'Unable to unserialize value. Error: Control character error, possibly incorrectly encoded'
            )],
            'an entity Magento cannot find' => [new NoSuchEntityException()],
        ];
    }

    /**
     * Any other error — the database gone, a bug — still stops the import,
     * as before: skipping would quietly drop every order after it.
     */
    public function testAnyOtherErrorStillStopsTheImport(): void
    {
        $builder = $this->createMock(OrderPayloadBuilder::class);
        $builder->method('build')->willThrowException(new \RuntimeException('database gone'));
        $skippedRow = $this->createMock(SkippedRow::class);
        $skippedRow->expects(self::never())->method('record');
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::never())->method('recordProgress');
        $calls = [];

        $this->expectExceptionMessage('database gone');
        $this->processor(
            $jobManager,
            $this->historyImport([[['entity_id' => '11', 'created_at' => '2026-01-01 10:00:00']]], $calls),
            $builder,
            $this->queue(),
            [11],
            $skippedRow
        )->process($this->job(3));
    }

    /**
     * @param list<list<array{entity_id: string, created_at: string}>> $pages
     * @param list<array{string, string}> $calls the window start and cursor each page was read with
     */
    private function historyImport(array $pages, array &$calls): HistoryImport&MockObject
    {
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('begin')->willReturn(self::HISTORY);
        $historyImport->method('ordersPage')->willReturnCallback(
            static function (string $from, string $until, string $cursor) use (&$pages, &$calls): array {
                $calls[] = [$from, $cursor];

                return array_shift($pages) ?? [];
            }
        );

        return $historyImport;
    }

    private function queue(): IngestQueue&MockObject
    {
        $queue = $this->createMock(IngestQueue::class);
        $queue->method('countPending')->willReturn(0);

        return $queue;
    }

    private function builder(): OrderPayloadBuilder
    {
        $builder = $this->createMock(OrderPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static fn (Order $order): array => ['external_order_id' => (string)$order->getEntityId()]
        );

        return $builder;
    }

    /**
     * @param int[] $orderIds the orders the store holds
     */
    private function processor(
        JobManager $jobManager,
        HistoryImport $historyImport,
        OrderPayloadBuilder $builder,
        IngestQueue $ingestQueue,
        array $orderIds = [],
        ?SkippedRow $skippedRow = null
    ): EngineOrdersProcessor {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(function () use ($orderIds): Collection {
            $wanted = [];
            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function (string $field, array $condition) use (&$wanted, $collection): Collection {
                    $wanted = array_map('intval', $condition['in']);

                    return $collection;
                }
            );
            $collection->method('getItems')->willReturnCallback(
                function () use (&$wanted, $orderIds): array {
                    return array_map(
                        fn (int $id): Order => $this->order($id),
                        array_values(array_intersect($orderIds, $wanted))
                    );
                }
            );

            return $collection;
        });

        return new EngineOrdersProcessor(
            $jobManager,
            $collectionFactory,
            $builder,
            $ingestQueue,
            $skippedRow ?? $this->createMock(SkippedRow::class),
            $historyImport
        );
    }

    private function job(?int $total): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(5);
        $job->method('getJobType')->willReturn(Job::TYPE_ORDERS);
        $job->method('getData')->willReturn($total);
        $job->method('getCursorValue')->willReturn('');

        return $job;
    }

    private function order(int $entityId): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getIncrementId')->willReturn((string)$entityId);

        return $order;
    }
}
