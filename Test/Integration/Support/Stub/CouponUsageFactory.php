<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\SalesRule\Model\ResourceModel\Coupon;

use Magento\Framework\App\ObjectManager;

/**
 * Integration-test stand-in for Magento's code-generated coupon usage
 * factory (see SubscriberFactory): it creates the real usage resource, as
 * the generated one does.
 */
if (!class_exists(UsageFactory::class, false)) {
    class UsageFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Usage
        {
            return ObjectManager::getInstance()->create(Usage::class, $data);
        }
    }
}
