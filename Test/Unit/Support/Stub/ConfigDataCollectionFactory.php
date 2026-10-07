<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Config\Model\ResourceModel\Config\Data;

/**
 * Unit-test stub: Magento factory classes are code-generated at runtime and do
 * not exist under vendor/, so constructor type hints against them cannot be
 * satisfied in unit tests. Declared only when absent (loaded via require_once
 * from tests, never autoloaded in production code paths). Tests mock create()
 * to return a prepared collection.
 */
if (!class_exists(CollectionFactory::class, false)) {
    class CollectionFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Collection
        {
            throw new \RuntimeException('ConfigDataCollectionFactory is not available in unit tests');
        }
    }
}
