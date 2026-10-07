<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineOrdersProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

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
            $ingestQueue
        );

        $this->expectExceptionMessage('count died');
        $processor->process($job);
    }
}
