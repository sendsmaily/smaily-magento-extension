<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Plugin\SalesRule;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\Utility;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Discount\CouponExpiry;
use Smaily\Connect\Plugin\SalesRule\RejectExpiredCoupon;

/**
 * CON-198: only an expired code of the extension's stops a rule that
 * Magento lets apply; Magento's own answer is otherwise kept.
 */
class RejectExpiredCouponTest extends TestCase
{
    public function testARuleMagentoRefusesStaysRefused(): void
    {
        $expiry = $this->createMock(CouponExpiry::class);
        $expiry->expects(self::never())->method('isExpired');

        self::assertFalse($this->after($expiry, false, Rule::COUPON_TYPE_SPECIFIC, 'CODE'));
    }

    public function testARuleWithoutACouponIsNotLookedUp(): void
    {
        $expiry = $this->createMock(CouponExpiry::class);
        $expiry->expects(self::never())->method('isExpired');

        self::assertTrue($this->after($expiry, true, Rule::COUPON_TYPE_NO_COUPON, 'CODE'));
        self::assertTrue($this->after($expiry, true, Rule::COUPON_TYPE_SPECIFIC, ''));
    }

    public function testAnExpiredCodeStopsTheRule(): void
    {
        $expiry = $this->createMock(CouponExpiry::class);
        $expiry->method('isExpired')->with('CODE', 5, 3)->willReturnOnConsecutiveCalls(true, false);

        self::assertFalse($this->after($expiry, true, Rule::COUPON_TYPE_SPECIFIC, 'CODE'));
        self::assertTrue($this->after($expiry, true, Rule::COUPON_TYPE_SPECIFIC, 'CODE'));
    }

    private function after(CouponExpiry $expiry, bool $result, int $couponType, string $code): bool
    {
        /** @var Rule $rule */
        $rule = (new \ReflectionClass(Rule::class))->newInstanceWithoutConstructor();
        $rule->setData(['id' => 5, 'coupon_type' => $couponType]);
        /** @var Quote $quote */
        $quote = (new \ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
        $quote->setData(['store_id' => 3, 'coupon_code' => $code]);
        /** @var Address $address */
        $address = (new \ReflectionClass(Address::class))->newInstanceWithoutConstructor();
        $address->setQuote($quote);

        return (new RejectExpiredCoupon($expiry))->afterCanProcessRule(
            $this->createMock(Utility::class),
            $result,
            $rule,
            $address
        );
    }
}
