<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\Framework;

/**
 * Integration-test stand-in for Magento's code-generated data object
 * factory (see SubscriberFactory).
 */
if (!class_exists(DataObjectFactory::class, false)) {
    class DataObjectFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): DataObject
        {
            return new DataObject($data);
        }
    }
}
