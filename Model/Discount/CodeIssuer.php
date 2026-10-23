<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Discount;

use Magento\Framework\Exception\LocalizedException;
use Magento\SalesRule\Helper\Coupon as CouponHelper;
use Magento\SalesRule\Model\Coupon\MassgeneratorFactory;

/**
 * Makes one shopper's discount code from a cart price rule (CON-198), with
 * Magento's own coupon generation for a rule with "Use Auto Generation" —
 * the generator behind the rule's Manage Coupon Codes, in the code format
 * of Stores > Configuration > Customers > Promotions > Auto Generated
 * Specific Coupon Codes. The code is the rule's like any code generated
 * there: its discount, conditions, customer groups and uses per customer
 * are the rule's. It can be used once (Uses per Coupon 1), and its last
 * valid day is $days days after today in the store's timezone, never after
 * the rule's To date.
 *
 * Magento's coupon management API (CouponManagementInterface::generate())
 * is not used: it gives every code the rule's own Uses per Coupon, and no
 * Magento API gives a code a day of its own (CouponExpiry).
 */
class CodeIssuer
{
    public function __construct(
        private readonly RuleSource $rules,
        private readonly MassgeneratorFactory $generatorFactory,
        private readonly CouponHelper $couponHelper,
        private readonly CouponExpiry $expiry,
        private readonly StoreDate $storeDate
    ) {
    }

    /**
     * A new single-use code of the rule and its last valid day (`Y-m-d`),
     * or null when no code can come from the rule for this website today
     * (RuleSource::usable()).
     *
     * @return array{code: string, expires_at: string}|null
     * @throws LocalizedException when Magento cannot generate the code
     */
    public function issue(int $ruleId, int $days, int $storeId, int $websiteId): ?array
    {
        $rule = $this->rules->usable($ruleId, $websiteId, $this->storeDate->today($storeId));
        if ($rule === null) {
            return null;
        }
        $lastDay = $this->storeDate->today($storeId, $days);
        if ($rule['to_date'] !== '' && $rule['to_date'] < $lastDay) {
            $lastDay = $rule['to_date'];
        }

        $format = (string)$this->couponHelper->getDefaultFormat();
        $generator = $this->generatorFactory->create();
        $generator->setData([
            'rule_id' => $ruleId,
            'qty' => 1,
            'length' => (int)$this->couponHelper->getDefaultLength(),
            // Magento's own default (`1`) names no format; the generator's
            // own fallback is alphanumeric.
            'format' => isset($this->couponHelper->getFormatsList()[$format])
                ? $format
                : CouponHelper::COUPON_FORMAT_ALPHANUMERIC,
            'prefix' => (string)$this->couponHelper->getDefaultPrefix(),
            'suffix' => (string)$this->couponHelper->getDefaultSuffix(),
            'dash' => (int)$this->couponHelper->getDefaultDashInterval(),
            'uses_per_coupon' => 1,
            'usage_per_customer' => $rule['uses_per_customer'],
        ]);
        $generator->generatePool();
        $code = (string)($generator->getGeneratedCodes()[0] ?? '');
        if ($code === '') {
            throw new LocalizedException(__('Magento generated no discount code.'));
        }
        $this->expiry->write($code, $lastDay);

        return ['code' => $code, 'expires_at' => $lastDay];
    }
}
