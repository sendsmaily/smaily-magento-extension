<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Customer\Api\Data;

/**
 * Integration-test stub: Magento factory classes are code-generated at
 * runtime and do not exist under vendor/, so constructor type hints against
 * them cannot be satisfied without the real Magento code generator. Declared
 * only when absent (loaded via require_once from tests, never autoloaded in
 * production code paths). create() throws — the tests that need it mock it.
 */
if (!class_exists(CustomerInterfaceFactory::class, false)) {
    class CustomerInterfaceFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): CustomerInterface
        {
            throw new \RuntimeException('CustomerInterfaceFactory is not available in integration tests');
        }
    }
}
