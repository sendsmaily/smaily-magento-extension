<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Directory\Model;

/**
 * Integration-test stand-in for Magento's code-generated currency factory
 * (see SubscriberFactory). A test hands the store its currencies, so
 * nothing creates one: create() throws.
 */
if (!class_exists(CurrencyFactory::class, false)) {
    class CurrencyFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Currency
        {
            throw new \RuntimeException('CurrencyFactory is not available in integration tests');
        }
    }
}
