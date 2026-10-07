<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical order import into the engine ingest queue. Orders whose state
 * is not representable in the engine enum are counted as processed and
 * skipped (same rule as the live observer).
 */
class EngineOrdersProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 100;
    private const TIME_BUDGET_SECONDS = 15;
    private const QUEUE_BACKLOG_LIMIT = 500;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        if ($this->ingestQueue->countPending(Client::DOMAIN_ORDERS) >= self::QUEUE_BACKLOG_LIMIT) {
            return;
        }

        // Taken up before the count, which can die on a large store: a job
        // that dies before its first page is then running and not moved,
        // so the tick sets it aside like any other (PRO-3950).
        $this->jobManager->markRunning($job);
        if ($job->getData('total_count') === null) {
            $job->setData('total_count', $this->orderCollectionFactory->create()->getSize());
        }

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        do {
            $cursor = (int)$job->getCursorValue();
            $collection = $this->orderCollectionFactory->create();
            $collection->addFieldToFilter('entity_id', ['gt' => $cursor])
                ->setOrder('entity_id', 'ASC')
                ->setPageSize(self::PAGE_SIZE);

            $orders = [];
            foreach ($collection->getItems() as $order) {
                if ($order instanceof Order) {
                    $orders[] = $order;
                }
            }
            if (!$orders) {
                $this->jobManager->complete($job);

                return;
            }

            $processed = 0;
            $newCursor = $cursor;
            foreach ($orders as $order) {
                $newCursor = max($newCursor, (int)$order->getEntityId());
                $item = $this->payloadBuilder->build($order);
                if ($item !== null) {
                    $this->ingestQueue->enqueue(
                        Client::DOMAIN_ORDERS,
                        $item,
                        (string)$order->getIncrementId()
                    );
                }
                $processed++;
            }
            $this->jobManager->recordProgress($job, $processed, 0, (string)$newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_ORDERS) >= self::QUEUE_BACKLOG_LIMIT) {
                return;
            }
        } while (microtime(true) < $deadline);
    }
}
