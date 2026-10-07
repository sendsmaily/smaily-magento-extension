<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Product hard-delete → engine removal (contract §3b, PRO-1231; mirrors
 * the Woo PRO-1230 split). catalog_product_delete_before fires only for a
 * real deletion — a merely disabled/hidden product goes through
 * ProductSaveAfter's in_stock=false soft path instead.
 *
 * Routing:
 * - Parent/standalone product → ONE catalog/remove queue row carrying the
 *   raw entity id (the exact `tags.product_id` the catalog sync emits);
 *   the engine tombstones every matching row (in_stock=false +
 *   recommendable=false, rows kept). The per-SKU soft tombstone is NOT
 *   also enqueued — §3b is strictly stronger. A never-ingested id comes
 *   back as `not_found`, a contract-defined success, so sends are never
 *   suppressed (PRO-1239: upsert/tombstone ordering is engine-side).
 * - Configurable CHILD (parent lives on) → the per-SKU in_stock=false
 *   upsert, as before. §3b is product-level and keyed on the shared parent
 *   id — firing it here would wrongly tombstone the surviving parent and
 *   sibling variants.
 */
class ProductDeleteBefore implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ParentProductResolver $parentProductResolver,
        private readonly CatalogIngest $catalogIngest
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->settings->isConnected()) {
            return;
        }

        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product) {
            return;
        }
        $productId = (int)$product->getId();
        if ($productId <= 0) {
            return;
        }

        if ($this->parentProductResolver->isConfigurableChild($productId)) {
            // Forced: at delete_before the product is still perfectly
            // ingestible, so the funnel cannot infer the tombstone itself.
            $this->catalogIngest->enqueueTombstone($product);

            return;
        }

        $this->catalogIngest->enqueueRemovals([$productId]);
    }
}
