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
 * Historical order import into the engine ingest queue, newest first
 * (contract v1.20.0 §25, CON-241): the orders of the history import's start
 * part (HistoryImport, the last `start_months` months), then the older ones
 * down to its horizon; no older order is read. Only orders placed before
 * the import began are read — a newer one goes through the live sync — so
 * the walk can resume anywhere: the cursor is the last order queued,
 * "created_at|entity_id", and each page reads the orders before it
 * (HistoryImport::ordersPage()). Once the start part is queued, the newest
 * queue row of the job marks its end, for the progress report.
 *
 * Only orders in a state the engine takes are read (same rule as the live
 * observer); an order the payload builder still leaves out (no email) is
 * counted as processed and not queued. An order whose row cannot be built
 * from what Magento stores is skipped and the import goes on (SkippedRow,
 * CON-227).
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
        private readonly SkippedRow $skippedRow,
        private readonly HistoryImport $historyImport
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
        $history = $this->historyImport->begin($job);
        if ($job->getData('total_count') === null) {
            $job->setData('total_count', $history['orders_expected']);
        }

        $jobId = (int)$job->getId();
        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        do {
            $startPartQueued = $history['start_part_queued_to'] !== null;
            $rows = $this->historyImport->ordersPage(
                $startPartQueued ? $history['horizon'] : $history['start_from'],
                $history['until'],
                $job->getCursorValue(),
                self::PAGE_SIZE
            );
            if (!$rows) {
                if (!$startPartQueued) {
                    $history = $this->historyImport->update([
                        'start_part_queued_to' => $this->ingestQueue->lastRowId($jobId),
                    ]);
                    continue;
                }
                $this->jobManager->complete($job);

                return;
            }

            $orders = $this->load(array_column($rows, 'entity_id'));
            $processed = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $order = $orders[(int)$row['entity_id']] ?? null;
                if ($order === null) {
                    $processed++; // Deleted since the page was read.
                    continue;
                }
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
                        (string)$order->getIncrementId(),
                        null,
                        null,
                        $jobId
                    );
                }
                $processed++;
            }
            $last = end($rows);
            $cursor = $last['created_at'] . '|' . $last['entity_id'];
            $this->jobManager->recordProgress($job, $processed, $skipped, $cursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_ORDERS) >= IngestPace::IMPORT_BACKLOG_ROWS) {
                return;
            }
        } while (microtime(true) < $deadline);
    }

    /**
     * @param array<int, int|string> $ids
     * @return array<int, Order> by entity id
     */
    private function load(array $ids): array
    {
        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('entity_id', ['in' => $ids]);

        $orders = [];
        foreach ($collection->getItems() as $order) {
            if ($order instanceof Order) {
                $orders[(int)$order->getEntityId()] = $order;
            }
        }

        return $orders;
    }
}
