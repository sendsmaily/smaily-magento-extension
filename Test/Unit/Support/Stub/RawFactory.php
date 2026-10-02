<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework\Controller\Result;

/**
 * Unit-test stub: Magento factory classes are code-generated at runtime and do
 * not exist under vendor/, so constructor type hints against them cannot be
 * satisfied in unit tests. Declared only when absent (loaded via require_once
 * from tests, never autoloaded in production code paths). Tests mock it;
 * create() of the stub itself throws.
 */
if (!class_exists(RawFactory::class, false)) {
    class RawFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Raw
        {
            throw new \RuntimeException('RawFactory is not available in unit tests');
        }
    }
}
