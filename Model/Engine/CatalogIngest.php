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
 * the delete observers' soft tombstones and §3b removals, and the catalog
 * import processor alike.
 *
 * Product save, the tombstone and the backfill pages already hold the
 * product and queue its row at once. The stock hooks do not (PRO-1967): a
 * stock write runs inside the order, shipment or import transaction, so they
 * only record which products changed — a `catalog_changed` row holding the
 * product id, nothing loaded or built — and the ingest flusher builds those
 * rows in a batch each minute (buildChanged(): one product collection,
 * one insert), so the row goes out in the same run as before.
 *
 * The engine-connected gate lives here, once, rather than in every caller;
 * the delete paths ask it before they build anything, so their callers hold it.
 *
 * A product goes to every Campaign Intelligence connection that owns it
 * (CON-237, Connection::ownsProductOn()): a connection whose engine store
 * is connected and serves one of the product's websites, and the primary
 * for a product on no website. Each connection gets the row built for it
 * (CatalogPayloadBuilder::build()) carrying its canonical store view; under
 * one store that is the primary alone, with the row it always got. A
 * catalog import queues the rows of its own connection only. A §3b removal
 * goes to every connected connection: the engine answers `not_found` for a
 * product it never had, a success. A product that leaves a connection's
 * websites is not detected at the save: the connection's next nightly
 * manifest (CatalogManifest) leaves it out, and the engine removes it. The
 * `catalog_changed` markers belong to no connection.
 *
 * Duplicates collapse in two places, neither of them queue-wide dedupe:
 * a byte-identical row queued twice in a row for one connection within one
 * request (see $lastPayloadHashes); and in buildChanged(), several markers
 * for one product build one row per connection, which the queue leaves out
 * when the product's newest unsent row of that connection is already that
 * row (IngestQueue::enqueueChangedPayloads()) — one product save reaches
 * product save and the stock hooks, and queues one row, not two.
 */
class CatalogIngest
{
    /** Queue domain of a "this product changed" marker; never sent. */
    public const DOMAIN_CHANGED = 'catalog_changed';

    /** Markers built per flusher run: the catalog domain's send batch. */
    private const BUILD_BATCH = 100;

    /** @var array<int, int> connection key => hash of the row queued for it most recently in this request */
    private array $lastPayloadHashes = [];

    public function __construct(
        private readonly CatalogProductLoader $productLoader,
        private readonly ProductResource $productResource,
        private readonly CatalogPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly Logger $logger,
        private readonly Connections $connections
    ) {
    }

    /**
     * Queue the product's current catalog row — a tombstone once it has left
     * the sellable set (disabled, hidden); the engine never deletes — for
     * every connection that owns it, or with $connection for that one only
     * (a catalog import, which pages the connection's own products).
     * $jobId names the catalog import that queues it (CON-241).
     *
     * @return bool whether the row is queued for every connection it is for
     *     (a collapsed duplicate counts: the row is already there), false when
     *     no connected connection owns it or a build failed
     */
    public function enqueueProduct(Product $product, ?int $jobId = null, ?Connection $connection = null): bool
    {
        return $this->enqueue($product, false, $jobId, $connection);
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
     * Build these products' tombstone rows now, for a delete that removes
     * the products before any event sees it (a product import's Delete,
     * PRO-3768); enqueueBuilt() queues them once the delete happens. The
     * products load as one collection per connection, as in buildChanged().
     * The caller holds the engine-connected gate
     * (ProductImportDelete::isProductDelete()).
     *
     * @param int[] $productIds
     * @return array<int, array<int, array<string, mixed>>> product id => connection key => row
     *     (a failed build is left out, logged)
     */
    public function buildTombstones(array $productIds): array
    {
        return $productIds ? $this->buildForOwners($productIds, true) : [];
    }

    /**
     * Queue rows buildTombstones() or buildChanged() built, each connection's
     * rows for that connection.
     *
     * @param array<int|string, array<int, array<string, mixed>>> $rows product id => connection key => row
     */
    public function enqueueBuilt(array $rows): void
    {
        $byConnection = [];
        foreach ($rows as $productId => $connectionRows) {
            foreach ($connectionRows as $key => $row) {
                $byConnection[$key][$productId] = $row;
            }
        }
        foreach ($byConnection as $key => $payloads) {
            $connection = $this->connections->forKey($key);
            if ($connection !== null) {
                $this->ingestQueue->enqueueChangedPayloads(Client::DOMAIN_CATALOG, $payloads, $connection);
            }
        }
    }

    /**
     * Queue the §3b product-level removal of these products (contract §3b,
     * PRO-1231): one catalog_remove row per product and connected
     * connection, carrying the raw entity id — the `tags.product_id` the
     * catalog sync emits. For a product deleted in the admin
     * (ProductDeleteBefore) and by a product import's Delete
     * (ProductImportDelete); never for a configurable child, whose parent
     * lives on. The caller holds the engine-connected gate.
     *
     * @param int[] $productIds
     */
    public function enqueueRemovals(array $productIds): void
    {
        foreach ($this->connections->connected() as $connection) {
            $storeId = $connection->canonicalStoreId();
            $rows = [];
            foreach ($productIds as $productId) {
                $key = (string)$productId;
                $rows[] = ['payload' => ['product_id' => $key], 'entity_id' => $key, 'store_id' => $storeId];
            }

            $this->ingestQueue->enqueueMany(Client::DOMAIN_CATALOG_REMOVE, $rows);
        }
    }

    /**
     * Record that the product's stock changed; buildChanged() queues its row.
     */
    public function markProductChanged(int $productId): bool
    {
        return $productId > 0 && $this->connections->anyConnected() && $this->markChanged([$productId]);
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
        if (!$skus || !$this->connections->anyConnected()) {
            return false;
        }

        return $this->markChanged(array_map('intval', $this->productResource->getProductsIdsBySkus($skus)));
    }

    /**
     * Build the rows of the products the stock hooks marked changed — called
     * by the ingest flusher before it sends the catalog batch. The products
     * load as one collection per connected connection, as the backfill page
     * loads them (CatalogProductLoader); a product deleted since its marker
     * is not there, and its marker is dropped (the delete observer already
     * told the engine). Each connection that owns a product gets its row;
     * the queue leaves out a row identical to the product's newest unsent
     * row of that connection.
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

        $this->enqueueBuilt($this->buildForOwners($productIds, false));
        $this->ingestQueue->delete($markers);
    }

    /**
     * The rows of these products for each connected connection that owns
     * them, loaded at that connection's store view.
     *
     * @param int[] $productIds
     * @return array<int, array<int, array<string, mixed>>> product id => connection key => row
     */
    private function buildForOwners(array $productIds, bool $forceTombstone): array
    {
        $rows = [];
        foreach ($this->connections->connected() as $connection) {
            foreach ($this->loadProducts($productIds, $connection) as $product) {
                if (!$connection->ownsProductOn((array)$product->getWebsiteIds())) {
                    continue;
                }
                $item = $this->build($product, $forceTombstone, $connection);
                if ($item !== null) {
                    $rows[(int)$product->getId()][$connection->key()] = $item;
                }
            }
        }

        return $rows;
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
    private function loadProducts(array $productIds, Connection $connection): array
    {
        return $this->productLoader->load(static function (Collection $collection) use ($productIds): void {
            $collection->addFieldToFilter('entity_id', ['in' => $productIds]);
        }, $connection);
    }

    private function enqueue(
        Product $product,
        bool $forceTombstone,
        ?int $jobId = null,
        ?Connection $only = null
    ): bool {
        if ($only !== null) {
            $connections = $only->settings()->isConnected() ? [$only] : [];
        } else {
            $websiteIds = (array)$product->getWebsiteIds();
            $connections = array_filter(
                $this->connections->connected(),
                static fn (Connection $connection): bool => $connection->ownsProductOn($websiteIds)
            );
        }
        if (!$connections) {
            return false;
        }

        $queued = true;
        foreach ($connections as $connection) {
            $queued = $this->enqueueFor($product, $forceTombstone, $jobId, $connection) && $queued;
        }

        return $queued;
    }

    private function enqueueFor(Product $product, bool $forceTombstone, ?int $jobId, Connection $connection): bool
    {
        $item = $this->build($product, $forceTombstone, $connection);
        if ($item === null) {
            return false;
        }

        $hash = crc32((string)json_encode($item));
        if ($hash === ($this->lastPayloadHashes[$connection->key()] ?? null)) {
            return true; // The same hop of the same save, seen through another hook.
        }
        $this->lastPayloadHashes[$connection->key()] = $hash;

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CATALOG,
            $item,
            (string)$product->getId(),
            $connection->canonicalStoreId(),
            null,
            $jobId
        );

        return true;
    }

    /**
     * The product's row for a connection, or null when the build failed (logged).
     *
     * @return array<string, mixed>|null
     */
    private function build(Product $product, bool $forceTombstone, Connection $connection): ?array
    {
        try {
            return $forceTombstone || !$this->payloadBuilder->isIngestible($product)
                ? $this->payloadBuilder->buildTombstone($product, $connection)
                : $this->payloadBuilder->build($product, $connection);
        } catch (\Throwable $exception) {
            $this->logger->error('Catalog payload build failed', [
                'product_id' => $product->getId(),
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
