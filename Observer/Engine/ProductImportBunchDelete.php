<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\ProductImportDelete;

/**
 * Product import Delete → engine removal (PRO-3768): on
 * catalog_product_import_bunch_delete_commit_before — the bunch's products
 * are deleted, the transaction not yet committed — queue each deleted
 * product's removal, as ProductDeleteBefore does for a product delete (see
 * ProductImportDelete). The Replace behaviour fires this event too; it is
 * left out there.
 */
class ProductImportBunchDelete implements ObserverInterface
{
    public function __construct(
        private readonly ProductImportDelete $productImportDelete
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $adapter = $event->getData('adapter');
        $deletedIds = $event->getData('ids_to_delete');
        if (!is_object($adapter) || !is_array($deletedIds) || !method_exists($adapter, 'getDataSourceModel')) {
            return;
        }

        $ids = method_exists($adapter, 'getIds') ? $adapter->getIds() : null;
        if ($this->productImportDelete->isProductDelete($adapter->getDataSourceModel(), $ids ?: null)) {
            $this->productImportDelete->enqueue($deletedIds);
        }
    }
}
