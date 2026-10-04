<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The single place a product turns into a catalog ingest row — live hooks,
 * the delete observer's soft tombstone and the catalog import processor
 * alike.
 *
 * Product save, the tombstone and the backfill pages already hold the
 * product and queue its row at once. The stock hooks do not (PRO-1967): a
 * stock write runs inside the order, shipment or import transaction, so they
 * only record which products changed — a `catalog_changed` row holding the
 * product id, nothing loaded or built — and the ingest flusher builds those
 * rows in a batch each minute (buildChanged(): one product collection,
 * one insert), so the row goes out in the same run as before.
 *
 * The engine-connected gate lives here, once, rather than in every caller.
 *
 * Duplicates collapse in two places, neither of them queue-wide dedupe:
 * a byte-identical row queued twice in a row within one request (see
 * $lastPayloadHash); and in buildChanged(), several markers for one product
 * build one row, which the queue leaves out when the product's newest unsent
 * row is already that row (IngestQueue::enqueueChangedPayloads()) — one
 * product save reaches product save and the stock hooks, and queues one row,
 * not two.
 */
class CatalogIngest
{
    /** Queue domain of a "this product changed" marker; never sent. */
    public const DOMAIN_CHANGED = 'catalog_changed';

    /** Markers built per flusher run: the catalog domain's send batch. */
    private const BUILD_BATCH = 100;

    /** Hash of the row queued most recently in this request. */
    private ?int $lastPayloadHash = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly CatalogProductLoader $productLoader,
        private readonly ProductResource $productResource,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly Logger $logger
    ) {
    }

    /**
     * Queue the product's current catalog row — a tombstone once it has left
     * the sellable set (disabled, hidden); the engine never deletes.
     *
     * @return bool whether the row is queued (a collapsed duplicate counts:
     *     the row is already there), false when skipped or the build failed
     */
    public function enqueueProduct(Product $product): bool
    {
        return $this->enqueue($product, false);
    }

    /**
     * Queue the product's tombstone row regardless of whether it is still
     * ingestible — the hard-delete path fires before the row is gone, so the
     * product still looks perfectly sellable at that moment.
     */
    public function enqueueTombstone(Product $product): bool
    {
        return $this->enqueue($product, true);
    }

    /**
     * Record that the product's stock changed; buildChanged() queues its row.
     */
    public function markProductChanged(int $productId): bool
    {
        return $productId > 0 && $this->settings->isConnected() && $this->markChanged([$productId]);
    }

    /**
     * Record that these products' stock changed: one sku lookup, one insert,
     * however many skus — a sku listed twice (two order lines, two sources)
     * counts once. A blank or unknown sku is skipped.
     *
     * @param string[] $skus
     */
    public function markSkusChanged(array $skus): bool
    {
        $skus = array_values(array_unique(array_filter($skus, static fn (string $sku): bool => trim($sku) !== '')));
        if (!$skus || !$this->settings->isConnected()) {
            return false;
        }

        return $this->markChanged(array_map('intval', $this->productResource->getProductsIdsBySkus($skus)));
    }

    /**
     * Build the rows of the products the stock hooks marked changed — called
     * by the ingest flusher before it sends the catalog batch. The products
     * load as one collection, as the backfill page loads them
     * (CatalogProductLoader); a product deleted since its marker is not
     * there, and its marker is dropped (the delete observer already told the
     * engine). The queue leaves out a row identical to the product's newest
     * unsent row.
     */
    public function buildChanged(): void
    {
        $markers = $this->ingestQueue->claimBatch(self::DOMAIN_CHANGED, self::BUILD_BATCH);
        if (!$markers) {
            return;
        }

        $productIds = array_values(array_unique(array_map(
            static fn (IngestEvent $marker): int => (int)$marker->getEntityId(),
            $markers
        )));
        $storeId = $this->payloadBuilder->canonicalStoreId();

        $payloads = [];
        foreach ($this->loadProducts($productIds) as $product) {
            $item = $this->build($product, false);
            if ($item !== null) {
                $payloads[(string)$product->getId()] = $item;
            }
        }

        $this->ingestQueue->enqueueChangedPayloads(Client::DOMAIN_CATALOG, $payloads, $storeId);
        $this->ingestQueue->delete($markers);
    }

    /**
     * @param int[] $productIds
     */
    private function markChanged(array $productIds): bool
    {
        if (!$productIds) {
            return false;
        }

        $rows = [];
        foreach (array_unique($productIds) as $productId) {
            $rows[] = ['payload' => [], 'entity_id' => (string)$productId, 'store_id' => null];
        }

        return $this->ingestQueue->enqueueMany(self::DOMAIN_CHANGED, $rows) > 0;
    }

    /**
     * @param int[] $productIds
     * @return Product[]
     */
    private function loadProducts(array $productIds): array
    {
        return $this->productLoader->load(static function (Collection $collection) use ($productIds): void {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        });
    }

    private function enqueue(Product $product, bool $forceTombstone): bool
    {
        if (!$this->settings->isConnected()) {
            return false;
        }

        $item = $this->build($product, $forceTombstone);
        if ($item === null) {
            return false;
        }

        $hash = crc32((string)json_encode($item));
        if ($hash === $this->lastPayloadHash) {
            return true; // The same hop of the same save, seen through another hook.
        }
        $this->lastPayloadHash = $hash;

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CATALOG,
            $item,
            (string)$product->getId(),
            $this->payloadBuilder->canonicalStoreId()
        );

        return true;
    }

    /**
     * The product's row, or null when the build failed (logged).
     *
     * @return array<string, mixed>|null
     */
    private function build(Product $product, bool $forceTombstone): ?array
    {
        try {
            return $forceTombstone || !$this->payloadBuilder->isIngestible($product)
                ? $this->payloadBuilder->buildTombstone($product)
                : $this->payloadBuilder->build($product);
        } catch (\Throwable $exception) {
            $this->logger->error('Catalog payload build failed', [
                'product_id' => $product->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
