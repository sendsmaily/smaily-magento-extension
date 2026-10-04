<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;

/**
 * Loads products the way every catalog row built from a collection needs
 * them — the backfill/nightly re-sync page (EngineCatalogProcessor) and the
 * stock-change batch (CatalogIngest::buildChanged()) alike. Each caller adds
 * only its own filter.
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
        $collection = $this->productCollectionFactory->create();
        if (!$collection instanceof Collection) {
            return [];
        }
        $collection->setStoreId($this->payloadBuilder->canonicalStoreId());
        $collection->addAttributeToSelect(CatalogPayloadBuilder::PRODUCT_ATTRIBUTES);
        $collection->addUrlRewrite();
        $filter($collection);

        return array_values(array_filter(
            $collection->getItems(),
            static fn ($product): bool => $product instanceof Product
        ));
    }
}
