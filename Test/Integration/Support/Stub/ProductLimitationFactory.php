<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Product\Collection;

/**
 * Integration-test stub: Magento factory classes are code-generated at
 * runtime and do not exist under vendor/, so constructor type hints against
 * them cannot be satisfied without the real Magento code generator. Declared
 * only when absent (loaded via require_once from tests, never autoloaded in
 * production code paths). create() returns what the generated factory
 * would: a new, empty ProductLimitation — a real product collection's
 * constructor calls it.
 */
if (!class_exists(ProductLimitationFactory::class, false)) {
    class ProductLimitationFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): ProductLimitation
        {
            return new ProductLimitation();
        }
    }
}
