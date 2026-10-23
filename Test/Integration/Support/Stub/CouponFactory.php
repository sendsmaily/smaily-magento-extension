<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Magento\SalesRule\Model;

use Magento\Framework\App\ObjectManager;

/**
 * Integration-test stand-in for Magento's code-generated coupon factory (see
 * SubscriberFactory): it creates the real coupon model, as the generated one
 * does, for the discount code tests on Magento's coupon tables.
 */
if (!class_exists(CouponFactory::class, false)) {
    class CouponFactory
    {
        /**
         * @param array<string, mixed> $data
         */
        public function create(array $data = []): Coupon
        {
            return ObjectManager::getInstance()->create(Coupon::class, $data);
        }
    }
}
