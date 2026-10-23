<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\SalesRule\Model\Coupon;

use Magento\Framework\App\ObjectManager;

/**
 * Integration-test stand-in for Magento's code-generated coupon generator
 * factory (see SubscriberFactory): it creates the real generator, as the
 * generated one does.
 */
if (!class_exists(MassgeneratorFactory::class, false)) {
    class MassgeneratorFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Massgenerator
        {
            return ObjectManager::getInstance()->create(Massgenerator::class, $data);
        }
    }
}
