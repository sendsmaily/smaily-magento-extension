<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;

/**
 * Loads products the way every catalog row built from a collection needs
 * them — the catalog import page (EngineCatalogProcessor) and the
 * stock-change batch (CatalogIngest::buildChanged()) alike, and the nightly
 * manifest's page at the same scope. Each caller adds only its own filter.
 *
 * Loaded for one Campaign Intelligence connection (CON-237), a collection
 * is read at the connection's canonical store view and the import page,
 * its count and the manifest's page hold the connection's products only
 * (applyConnection()). A connection that serves every website — every
 * store run as one store — reads the store it always read, unfiltered:
 * the same query.
 */
class CatalogProductLoader
{
    public function __construct(
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly CatalogPayloadBuilder $payloadBuilder
    ) {
    }

    /**
     * Explicit canonical scope (PRO-1352/1353) — without it the collection
     * falls back to Magento's implicit current-store resolver, an
     * undocumented scope that depends on the invoking CLI/cron context and
     * can disagree with the live save path. Set before addUrlRewrite(), which
     * reads the collection's store id at call time.
     *
     * No addPriceData() and no store/website filter (PRO-2506): Magento
     * INNER JOINs the price index on the scope's website, which drops every
     * product outside the default website (and every product the price index
     * leaves out). The collection holds every product; CatalogPayloadBuilder
     * reads each price at the store it belongs to (PRO-1458), as on the live
     * path. `tax_class_id` came from the price index before; it is one of the
     * builder's PRODUCT_ATTRIBUTES, the attributes a collection-loaded row
     * needs to be the row a product save sends (PRO-3692).
     *
     * No flat-catalog switch is needed: Magento reads the flat product tables
     * only in the frontend area (Flat\State's `isAvailable` is true only in
     * Magento_Catalog's etc/frontend/di.xml), and these loads run in cron.
     *
     * Read at the connection's canonical store view; not filtered by its
     * websites — the caller's filter picks the products.
     *
     * @param callable(Collection): void $filter the caller's own filter, order and page size
     * @return Product[]
     */
    public function load(callable $filter, Connection $connection): array
    {
        return $this->loadSelecting(CatalogPayloadBuilder::PRODUCT_ATTRIBUTES, true, $filter, $connection);
    }

    /**
     * The catalog import's page (EngineCatalogProcessor): load() for the
     * connection's products after $afterId, in entity id order.
     *
     * @return Product[]
     */
    public function loadPage(int $afterId, int $pageSize, Connection $connection): array
    {
        if (!$this->hasProducts($connection)) {
            return [];
        }

        return $this->load(static function (Collection $collection) use ($afterId, $pageSize, $connection): void {
            self::applyConnection($collection, $connection);
            self::page($collection, $afterId, $pageSize);
        }, $connection);
    }

    /**
     * How many products the connection's catalog import pages through
     * (EngineCatalogProcessor's total): every product, at the same store
     * view and with the same website filter as loadPage().
     */
    public function count(Connection $connection): int
    {
        if (!$this->hasProducts($connection)) {
            return 0;
        }
        $collection = $this->productCollectionFactory->create();
        if ($collection instanceof Collection) {
            $collection->setStoreId($this->storeId($connection));
            self::applyConnection($collection, $connection);
        }

        return $collection->getSize();
    }

    /**
     * The nightly manifest's page (PRO-3854): the same scope and page as
     * loadPage(), less the products disabled at that scope, selecting only
     * what CatalogPayloadBuilder::manifestItem() reads — no URL rewrites, no
     * payload attributes — so a page of a large catalog stays small. The
     * status filter is added after setStoreId(), so it reads the canonical
     * store's value, falling back to the default one, as the selected
     * `status` does. The connection's products only, as loadPage().
     *
     * @return Product[]
     */
    public function loadForManifest(int $afterId, int $pageSize, Connection $connection): array
    {
        if (!$this->hasProducts($connection)) {
            return [];
        }

        return $this->loadSelecting(
            ['status', 'visibility'],
            false,
            static function (Collection $collection) use ($afterId, $pageSize, $connection): void {
                $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
                self::applyConnection($collection, $connection);
                self::page($collection, $afterId, $pageSize);
            },
            $connection
        );
    }

    /**
     * Narrow a collection to the products of a connection's catalog
     * (Connection::ownsProductOn()): the products on one of its websites
     * (catalog_product_website), and for the primary also the products on
     * no website. Never through the price index or a store filter
     * (PRO-2506). A connection that serves every website is not narrowed.
     */
    private static function applyConnection(Collection $collection, Connection $connection): void
    {
        if ($connection->servesAllWebsites()) {
            return;
        }
        $db = $collection->getConnection();
        $productWebsite = $collection->getTable('catalog_product_website');
        $conditions = [];
        if ($connection->websiteIds()) {
            $onWebsites = $db->select()
                ->from(['pw' => $productWebsite], ['product_id'])
                ->where('pw.website_id IN (?)', $connection->websiteIds());
            $conditions[] = 'e.entity_id IN (' . $onWebsites->assemble() . ')';
        }
        if ($connection->isPrimary()) {
            $anyWebsite = $db->select()
                ->from(['pw_any' => $productWebsite], ['product_id'])
                ->where('pw_any.product_id = e.entity_id');
            $conditions[] = 'NOT EXISTS (' . $anyWebsite->assemble() . ')';
        }
        $collection->getSelect()->where(implode(' OR ', $conditions));
    }

    /**
     * Whether the connection's catalog can hold any product: a connection
     * that is not the primary and serves no website has none.
     */
    private function hasProducts(Connection $connection): bool
    {
        return $connection->isPrimary() || $connection->websiteIds() !== [];
    }

    /**
     * The store view a connection's products are read at: the
     * installation's canonical store for a connection that serves every
     * website (CatalogPayloadBuilder::canonicalStoreId(), its canonical
     * store view), else the connection's own canonical store view.
     */
    private function storeId(Connection $connection): int
    {
        $storeId = $connection->canonicalStoreId();

        return $connection->servesAllWebsites() || $storeId === null
            ? $this->payloadBuilder->canonicalStoreId()
            : $storeId;
    }

    /**
     * One page by entity id: the products after $afterId, in id order.
     */
    private static function page(Collection $collection, int $afterId, int $pageSize): void
    {
        $collection->addFieldToFilter('entity_id', ['gt' => $afterId]);
        $collection->setOrder('entity_id', 'ASC');
        $collection->setPageSize($pageSize);
    }

    /**
     * @param string[] $attributes
     * @param callable(Collection): void $filter
     * @return Product[]
     */
    private function loadSelecting(
        array $attributes,
        bool $urlRewrite,
        callable $filter,
        Connection $connection
    ): array {
        $collection = $this->productCollectionFactory->create();
        if (!$collection instanceof Collection) {
            return [];
        }
        $collection->setStoreId($this->storeId($connection));
        $collection->addAttributeToSelect($attributes);
        if ($urlRewrite) {
            $collection->addUrlRewrite();
        }
        $filter($collection);

        return array_values(array_filter(
            $collection->getItems(),
            static fn ($product): bool => $product instanceof Product
        ));
    }
}
