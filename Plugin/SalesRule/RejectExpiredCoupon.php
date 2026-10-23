<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\SalesRule;

use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Utility;
use Smaily\Connect\Model\Discount\CouponExpiry;

/**
 * A discount code an automation sent stops working after its last valid
 * day (CON-198). Magento decides in Utility::canProcessRule() whether a
 * rule applies to a cart — its coupon's uses among the checks — but never
 * reads a coupon's own day, so this refuses the rule there for the
 * extension's own expired codes (CouponExpiry). The cart then shows
 * Magento's own "The coupon code isn't valid" answer, as for a code used
 * up. Every other coupon and rule is left to Magento.
 */
class RejectExpiredCoupon
{
    public function __construct(
        private readonly CouponExpiry $expiry
    ) {
    }

    /**
     * Refuse the rule for an expired code of the extension's.
     *
     * @param Utility $subject
     * @param bool $result
     * @param Rule $rule
     * @param Address $address
     * @return bool
     */
    public function afterCanProcessRule(Utility $subject, $result, $rule, $address): bool
    {
        if (!$result || (int)$rule->getCouponType() === Rule::COUPON_TYPE_NO_COUPON) {
            return (bool)$result;
        }
        $quote = $address->getQuote();
        $code = trim((string)$quote->getCouponCode());
        if ($code === '') {
            return true;
        }

        return !$this->expiry->isExpired($code, (int)$rule->getId(), (int)$quote->getStoreId());
    }
}
