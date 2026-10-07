<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Helper;

/**
 * Unit-test stub: Magento factory classes are code-generated at runtime
 * and do not exist under vendor/, so constructor type hints against them
 * cannot be satisfied in unit tests. Declared only when absent (loaded via
 * require_once from tests, never autoloaded in production code paths).
 * create() throws — CatalogPayloadBuilder::imageUrl() treats that as
 * "no image", which is exactly the wanted unit-test behavior.
 */
if (!class_exists(ImageFactory::class, false)) {
    class ImageFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Image
        {
            throw new \RuntimeException('ImageFactory is not available in unit tests');
        }
    }
}
