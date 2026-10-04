<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Engine;

use Smaily\Connect\Model\Engine\CatalogIngest;

/**
 * Catalog ingest for MSI source-item writes — the Sources grid, a product
 * form's quantity, POST /V1/inventory/source-items, and the legacy stock item
 * MSI mirrors onto its default source. MSI writes the legacy cataloginventory
 * row with direct SQL, so none of these fire
 * cataloginventory_stock_item_save_after.
 *
 * Declared in di.xml on Magento\InventoryApi\Api\SourceItemsSaveInterface.
 * Not one MSI type is named here — `mixed` signatures, sku read by duck typing
 * — because MSI is removable: a plugin declared on a class that does not exist
 * is simply never wired, so an install without the Inventory modules still
 * compiles and runs on the legacy observer alone. sortOrder keeps us last in
 * the after-chain, behind MSI's own legacy-stock sync.
 */
class SourceItemsSave
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
     * @param mixed $sourceItems the saved source items
     */
    public function afterExecute(object $subject, mixed $result, mixed $sourceItems = null): mixed
    {
        $skus = [];
        foreach (is_array($sourceItems) ? $sourceItems : [] as $item) {
            if (is_object($item) && method_exists($item, 'getSku')) {
                $skus[] = (string)$item->getSku();
            }
        }

        // One marker per product, not per source; one insert for them all.
        if ($skus) {
            $this->catalogIngest->enqueueSkus(array_values(array_unique($skus)));
        }

        return $result;
    }
}
