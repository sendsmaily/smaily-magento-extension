<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical order import into the engine ingest queue. Orders whose state
 * is not representable in the engine enum are counted as processed and
 * skipped (same rule as the live observer). An order whose row cannot be
 * built from what Magento stores is skipped and the import goes on
 * (SkippedRow, CON-227).
 */
class EngineOrdersProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 100;
    private const TIME_BUDGET_SECONDS = 15;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly SkippedRow $skippedRow
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        if ($this->ingestQueue->countPending(Client::DOMAIN_ORDERS) >= IngestPace::IMPORT_BACKLOG_ROWS) {
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
            $skipped = 0;
            $newCursor = $cursor;
            foreach ($orders as $order) {
                $newCursor = max($newCursor, (int)$order->getEntityId());
                try {
                    $item = $this->payloadBuilder->build($order);
                } catch (\InvalidArgumentException | LocalizedException $exception) {
                    $this->skippedRow->record($job, (int)$order->getEntityId(), $exception);
                    $skipped++;
                    continue;
                }
                if ($item !== null) {
                    $this->ingestQueue->enqueue(
                        Client::DOMAIN_ORDERS,
                        $item,
                        (string)$order->getIncrementId()
                    );
                }
                $processed++;
            }
            $this->jobManager->recordProgress($job, $processed, $skipped, (string)$newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_ORDERS) >= IngestPace::IMPORT_BACKLOG_ROWS) {
                return;
            }
        } while (microtime(true) < $deadline);
    }
}
