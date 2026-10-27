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
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
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
 * one that blocks the imports behind it.
 */
class EngineOrdersProcessorTest extends TestCase
{
    public function testTheJobIsTakenUpBeforeTheOrderCount(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('getSize')->willThrowException(new \RuntimeException('count died'));
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturn(null);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('markRunning')->with($job);

        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);

        $processor = new EngineOrdersProcessor(
            $jobManager,
            $collectionFactory,
            $this->createMock(OrderPayloadBuilder::class),
            $ingestQueue,
            $this->createMock(SkippedRow::class)
        );

        $this->expectExceptionMessage('count died');
        $processor->process($job);
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
        $orders = [$this->order(11), $this->order(12), $this->order(13)];
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
        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);
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

        $job = $this->job();
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with($job, 2, 1, '13');

        $this->processor($jobManager, [$orders, []], $builder, $ingestQueue, new SkippedRow($logger))->process($job);

        self::assertSame([[Client::DOMAIN_ORDERS, '11'], [Client::DOMAIN_ORDERS, '13']], $enqueued);
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
        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);
        $skippedRow = $this->createMock(SkippedRow::class);
        $skippedRow->expects(self::never())->method('record');
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::never())->method('recordProgress');

        $this->expectExceptionMessage('database gone');
        $this->processor($jobManager, [[$this->order(11)]], $builder, $ingestQueue, $skippedRow)->process($this->job());
    }

    /**
     * @param array<int, Order[]> $pages the orders each page reads, in turn
     */
    private function processor(
        JobManager $jobManager,
        array $pages,
        OrderPayloadBuilder $builder,
        IngestQueue $ingestQueue,
        SkippedRow $skippedRow
    ): EngineOrdersProcessor {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(function () use (&$pages): Collection {
            $collection = $this->createMock(Collection::class);
            $collection->method('getSize')->willReturn(3);
            $collection->method('addFieldToFilter')->willReturnSelf();
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('setPageSize')->willReturnSelf();
            $collection->method('getItems')->willReturnCallback(static function () use (&$pages): array {
                return array_shift($pages) ?? [];
            });

            return $collection;
        });

        return new EngineOrdersProcessor($jobManager, $collectionFactory, $builder, $ingestQueue, $skippedRow);
    }

    private function job(): Job
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(5);
        $job->method('getJobType')->willReturn(Job::TYPE_ORDERS);
        $job->method('getData')->willReturn(3);

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
