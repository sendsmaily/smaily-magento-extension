<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model\ResourceModel\Product;

/**
 * Unit-test stub: Magento factory classes are code-generated at runtime and do
 * not exist under vendor/, so constructor type hints against them cannot be
 * satisfied in unit tests. Declared only when absent (loaded via require_once
 * from tests, never autoloaded in production code paths). create() throws —
 * tests that reach it are misconfigured; the paths under test here never do.
 */
if (!class_exists(CollectionFactory::class, false)) {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Collection
        {
            throw new \RuntimeException('ProductCollectionFactory is not available in unit tests');
        }
    }
}
