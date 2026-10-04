<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\CatalogIngest;

/**
 * Catalog ingest on a legacy stock-item save — every stock write that does
 * NOT go through a product save: the admin Advanced Inventory form, the
 * `PUT /V1/products/{sku}/stockItems/{id}` endpoint, the non-MSI order
 * decrement and the credit-memo restock. Without it the engine's
 * back-in-stock detection, which reads our catalog rows' `in_stock`, only
 * ever saw product edits.
 *
 * MSI's own writes are covered separately by Plugin\Engine\SourceItemsSave
 * and Plugin\Engine\SourceDeduction — MSI syncs the legacy row with direct
 * SQL, so no model save and no event.
 *
 * There is deliberately no "did the stock actually move?" gate here. Magento
 * hands the stock item to its writers through StockRegistryProvider, which
 * loads it through the resource model directly and so never calls
 * setOrigData(): every save looks like a change, gate or not (verified on the
 * sandbox — a plain product rename tripped it). The rows a product save
 * duplicates are collapsed one level down instead, in CatalogIngest.
 */
class StockItemSaveAfter implements ObserverInterface
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
        $item = $observer->getEvent()->getData('item');
        if (!$item instanceof StockItem) {
            return;
        }

        $this->catalogIngest->markProductChanged((int)$item->getProductId());
    }
}
