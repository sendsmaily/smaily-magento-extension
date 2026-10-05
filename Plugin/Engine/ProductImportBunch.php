<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Engine;

use Smaily\Connect\Model\Engine\ProductImportDelete;

/**
 * The product import's Delete reads each bunch from its data source right
 * before it deletes the bunch's products (Import\Product::_deleteProducts());
 * this is the last moment a configurable child's parent link and row data
 * exist, so its tombstone row is built here (PRO-3768, see
 * ProductImportDelete).
 *
 * Declared in di.xml on Magento\ImportExport\Model\ResourceModel\Import\Data.
 * No Magento_ImportExport type is named here: a plugin declared on a class
 * that does not exist is never wired, as the MSI plugins rely on.
 */
class ProductImportBunch
{
    public function __construct(
        private readonly ProductImportDelete $productImportDelete
    ) {
    }

    /**
     * @param mixed $result the bunch, or null after the last one
     * @param mixed $ids the import's ids
     */
    public function afterGetNextUniqueBunch(object $subject, mixed $result, mixed $ids = null): mixed
    {
        if (!is_array($result)) {
            $this->productImportDelete->endOfBunches();
        } elseif ($this->productImportDelete->isProductDelete($subject, is_array($ids) ? $ids : null)) {
            $this->productImportDelete->prepare($result);
        }

        return $result;
    }
}
