<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical catalog import (and the nightly re-sync, which is just a job of
 * this type): pages products through Engine\CatalogIngest — the same funnel
 * the live hooks use, so a paged row can never disagree with a live one — and
 * the flusher delivers at the engine's batch pace. A flood guard pauses the
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
        private readonly CatalogIngest $catalogIngest
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
        $collection = $this->productCollectionFactory->create();
        if (!$collection instanceof \Magento\Catalog\Model\ResourceModel\Product\Collection) {
            return [];
        }
        // Explicit canonical scope (PRO-1352/1353) — without it the
        // collection falls back to Magento's implicit current-store
        // resolver, an undocumented scope that depends on the invoking
        // CLI/cron context and can disagree with the live save path. Must
        // be set before addUrlRewrite(), which reads the collection's store
        // id at call time.
        //
        // No addPriceData() and no store/website filter (PRO-2506): Magento
        // INNER JOINs the price index on the scope's website, which drops
        // every product outside the default website (and every product the
        // price index leaves out). The page holds every product, as
        // countProducts() counts them; CatalogPayloadBuilder reads each
        // price at the store it belongs to (PRO-1458), as on the live path.
        // `tax_class_id` came from the price index before: the tax
        // adjustment of a price amount reads it.
        $collection->setStoreId($this->payloadBuilder->canonicalStoreId());
        $collection->addAttributeToSelect([
            'name', 'status', 'visibility', 'price', 'special_price', 'tax_class_id',
            'short_description', 'description', 'url_key', 'image',
            'small_image', 'thumbnail', 'manufacturer',
        ]);
        $collection->addFieldToFilter('entity_id', ['gt' => $cursor]);
        $collection->addUrlRewrite();
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize(self::PAGE_SIZE);

        $products = [];
        foreach ($collection->getItems() as $product) {
            if ($product instanceof Product) {
                $products[] = $product;
            }
        }

        return $products;
    }

    private function countProducts(): int
    {
        $collection = $this->productCollectionFactory->create();
        if ($collection instanceof \Magento\Catalog\Model\ResourceModel\Product\Collection) {
            // Same canonical scope as loadPage() (PRO-1353) — otherwise the
            // progress-bar total can disagree with the scoped pages on
            // multi-store installs.
            $collection->setStoreId($this->payloadBuilder->canonicalStoreId());
        }

        return $collection->getSize();
    }
}
