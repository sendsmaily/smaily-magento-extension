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
     * @param callable(Collection): void $filter the caller's own filter, order and page size
     * @return Product[]
     */
    public function load(callable $filter): array
    {
        return $this->loadSelecting(CatalogPayloadBuilder::PRODUCT_ATTRIBUTES, true, $filter);
    }

    /**
     * The catalog import's page (EngineCatalogProcessor): load() for the
     * products after $afterId, in entity id order.
     *
     * @return Product[]
     */
    public function loadPage(int $afterId, int $pageSize): array
    {
        return $this->load(static function (Collection $collection) use ($afterId, $pageSize): void {
            self::page($collection, $afterId, $pageSize);
        });
    }

    /**
     * The nightly manifest's page (PRO-3854): the same scope and page as
     * loadPage(), less the products disabled at that scope, selecting only
     * what CatalogPayloadBuilder::manifestItem() reads — no URL rewrites, no
     * payload attributes — so a page of a large catalog stays small. The
     * status filter is added after setStoreId(), so it reads the canonical
     * store's value, falling back to the default one, as the selected
     * `status` does.
     *
     * @return Product[]
     */
    public function loadForManifest(int $afterId, int $pageSize): array
    {
        return $this->loadSelecting(
            ['status', 'visibility'],
            false,
            static function (Collection $collection) use ($afterId, $pageSize): void {
                $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
                self::page($collection, $afterId, $pageSize);
            }
        );
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
    private function loadSelecting(array $attributes, bool $urlRewrite, callable $filter): array
    {
        $collection = $this->productCollectionFactory->create();
        if (!$collection instanceof Collection) {
            return [];
        }
        $collection->setStoreId($this->payloadBuilder->canonicalStoreId());
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
