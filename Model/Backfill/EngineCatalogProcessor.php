<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical catalog import: pages products through Engine\CatalogIngest —
 * the same funnel the live hooks use, so a paged row can never disagree with
 * a live one — and the flusher delivers at the engine's batch pace. A flood guard pauses the
 * job while the queue backlog is high, so live events are never starved.
 */
class EngineCatalogProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 100;
    private const TIME_BUDGET_SECONDS = 15;
    private const QUEUE_BACKLOG_LIMIT = 500;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly CatalogIngest $catalogIngest,
        private readonly CatalogProductLoader $productLoader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        if ($this->ingestQueue->countPending(Client::DOMAIN_CATALOG) >= self::QUEUE_BACKLOG_LIMIT) {
            return; // Let the flusher drain before enqueueing more.
        }

        if ($job->getData('total_count') === null) {
            $job->setData('total_count', $this->countProducts());
        }
        $this->jobManager->markRunning($job);

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        do {
            $cursor = (int)$job->getCursorValue();
            $products = $this->loadPage($cursor);
            if (!$products) {
                $this->jobManager->complete($job);

                return;
            }

            $processed = 0;
            $failed = 0;
            $newCursor = $cursor;
            foreach ($products as $product) {
                $newCursor = max($newCursor, (int)$product->getId());
                if ($this->catalogIngest->enqueueProduct($product)) {
                    $processed++;
                } else {
                    $failed++;
                }
            }
            $this->jobManager->recordProgress($job, $processed, $failed, (string)$newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_CATALOG) >= self::QUEUE_BACKLOG_LIMIT) {
                return;
            }
        } while (microtime(true) < $deadline);
    }

    /**
     * @return Product[]
     */
    private function loadPage(int $cursor): array
    {
        return $this->productLoader->load(static function (Collection $collection) use ($cursor): void {
            $collection->addFieldToFilter('entity_id', ['gt' => $cursor]);
            $collection->setOrder('entity_id', 'ASC');
            $collection->setPageSize(self::PAGE_SIZE);
        });
    }

    private function countProducts(): int
    {
        $collection = $this->productCollectionFactory->create();
        if ($collection instanceof Collection) {
            // Same canonical scope as loadPage() (PRO-1353) — otherwise the
            // progress-bar total can disagree with the scoped pages on
            // multi-store installs.
            $collection->setStoreId($this->payloadBuilder->canonicalStoreId());
        }

        return $collection->getSize();
    }
}
