<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Catalog\Model\Product;

/**
 * Integration-test stub: Magento factory classes are code-generated at
 * runtime and do not exist under vendor/, so constructor type hints against
 * them cannot be satisfied without the real Magento code generator. Declared
 * only when absent (loaded via require_once from tests, never autoloaded in
 * production code paths). create() throws — the paths under test never
 * reach it.
 */
if (!class_exists(OptionFactory::class, false)) {
    class OptionFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Option
        {
            throw new \RuntimeException('Product OptionFactory is not available in integration tests');
        }
    }
}
