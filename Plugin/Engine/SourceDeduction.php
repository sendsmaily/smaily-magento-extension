<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Engine;

use Smaily\Connect\Model\Engine\CatalogIngest;

/**
 * Catalog ingest for MSI source deductions — the shipment deduction (what
 * "order decrement" actually is once MSI is installed: placing an order only
 * writes a reservation) and the credit-memo return to stock. Neither goes
 * through SourceItemsSave, and neither fires
 * cataloginventory_stock_item_save_after.
 *
 * Declared in di.xml on
 * Magento\InventorySourceDeductionApi\Model\SourceDeductionServiceInterface.
 * Not one MSI type is named here — `mixed` signatures, sku read by duck typing
 * — because MSI is removable: a plugin declared on a class that does not exist
 * is simply never wired, so an install without the Inventory modules still
 * compiles and runs on the legacy observer alone. sortOrder keeps us last in
 * the after-chain, behind MSI's own legacy-stock sync.
 */
class SourceDeduction
{
    public function __construct(
        private readonly CatalogIngest $catalogIngest
    ) {
    }

    /**
     * Mark every affected product changed; the ingest flusher builds its
     * catalog row (PRO-1967 — nothing is loaded or built in this write's
     * transaction).
     *
     * @param mixed $request the source deduction request
     */
    public function afterExecute(object $subject, mixed $result, mixed $request = null): mixed
    {
        if (!is_object($request) || !method_exists($request, 'getItems')) {
            return $result;
        }

        $skus = [];
        foreach ((array)$request->getItems() as $item) {
            if (is_object($item) && method_exists($item, 'getSku')) {
                $skus[] = (string)$item->getSku();
            }
        }

        // One marker per product, not per order line; one insert for them all.
        $this->catalogIngest->markSkusChanged($skus);

        return $result;
    }
}
