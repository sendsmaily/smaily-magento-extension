<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Engine removal for products deleted by Magento's product import with the
 * Delete behaviour (System > Data Transfer > Import, `bin/magento import`;
 * PRO-3768). The import deletes each bunch of products with one SQL DELETE
 * and fires no catalog_product_delete_before, so ProductDeleteBefore never
 * sees them — the engine would keep them recommendable (contract §3b is
 * its only delete signal).
 *
 * The removal is the one ProductDeleteBefore queues: a §3b catalog_remove
 * row per product, and for a configurable child the per-SKU tombstone row
 * instead (§3b would remove the surviving parent and siblings). Two hooks,
 * because the import's only events come after the DELETE, when neither the
 * child's parent link nor its row data exist any more:
 * - prepare() — the bunch is read (Plugin\Engine\ProductImportBunch), the
 *   products still exist: the configurable children's tombstone rows are
 *   built and kept until the bunch is deleted.
 * - enqueue() — catalog_product_import_bunch_delete_commit_before
 *   (Observer\Engine\ProductImportBunchDelete), inside the delete's
 *   transaction, so the rows are queued exactly when the delete commits.
 *
 * Only the Delete behaviour: Replace also deletes through the same code,
 * but it creates the products again at once, so they are not gone.
 * No Magento_ImportExport type is named here (the module can be removed):
 * the import's data source is read by duck typing.
 */
class ProductImportDelete
{
    private const ENTITY_PRODUCT = 'catalog_product';
    private const BEHAVIOR_DELETE = 'delete';

    /**
     * The tombstone rows of the bunch being deleted, product id => row.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $tombstones = [];

    public function __construct(
        private readonly Settings $settings,
        private readonly ProductResource $productResource,
        private readonly ParentProductResolver $parentProductResolver,
        private readonly CatalogIngest $catalogIngest,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * Whether the import data source holds a product import with the Delete
     * behaviour (the data source is Magento's Import\Data resource model).
     *
     * @param array<int|string>|null $ids the import's ids, as the import reads its bunches
     */
    public function isProductDelete(object $dataSource, ?array $ids): bool
    {
        if (!$this->settings->isConnected()
            || !method_exists($dataSource, 'getEntityTypeCode')
            || !method_exists($dataSource, 'getBehavior')
        ) {
            return false;
        }

        return $dataSource->getEntityTypeCode($ids) === self::ENTITY_PRODUCT
            && $dataSource->getBehavior($ids) === self::BEHAVIOR_DELETE;
    }

    /**
     * Before the bunch is deleted: build the tombstone rows of the bunch's
     * configurable children while they still exist.
     *
     * @param array<int, array<string, mixed>> $bunch the import rows
     */
    public function prepare(array $bunch): void
    {
        $skus = [];
        foreach ($bunch as $row) {
            $sku = trim((string)($row['sku'] ?? ''));
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }

        $childIds = [];
        foreach ($skus ? $this->productResource->getProductsIdsBySkus(array_unique($skus)) : [] as $productId) {
            if ($this->parentProductResolver->isConfigurableChild((int)$productId)) {
                $childIds[] = (int)$productId;
            }
        }

        $this->tombstones = $this->catalogIngest->buildTombstones($childIds);
    }

    /**
     * The bunch is deleted: queue the removal of each deleted product.
     *
     * @param array<int|string> $deletedIds the entity ids the import deleted
     */
    public function enqueue(array $deletedIds): void
    {
        $tombstones = [];
        $removals = [];
        foreach (array_unique(array_map('intval', $deletedIds)) as $productId) {
            if ($productId <= 0) {
                continue;
            }
            if (isset($this->tombstones[$productId])) {
                $tombstones[$productId] = $this->tombstones[$productId];
                continue;
            }
            $key = (string)$productId;
            $removals[] = ['payload' => ['product_id' => $key], 'entity_id' => $key, 'store_id' => null];
        }
        $this->tombstones = [];

        $this->catalogIngest->enqueueBuilt($tombstones);
        $this->ingestQueue->enqueueMany(Client::DOMAIN_CATALOG_REMOVE, $removals);
    }
}
