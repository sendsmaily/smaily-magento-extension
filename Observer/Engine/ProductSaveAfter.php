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

/**
 * Catalog ingest on product save. Products that leave the sellable set
 * (disabled, hidden) are tombstoned via an in_stock=false upsert — the
 * engine never deletes. The engine-connected gate lives in CatalogIngest.
 */
class ProductSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly CatalogIngest $catalogIngest
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $product = $observer->getEvent()->getData('product');
        if (!$product instanceof Product) {
            return;
        }

        $this->catalogIngest->enqueueProduct($product);
    }
}
