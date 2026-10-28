<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Discount;

use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Discount\ShopperCodes;

/**
 * Which discount codes a queued automation row carries for an address
 * (CON-225): the export and erasure of a shopper's codes start here.
 */
class ShopperCodesTest extends TestCase
{
    private const EMAIL = 'erase-test-1@example.test';

    public function testReadsTheCodesOfEveryAutomationFromTheAddress(): void
    {
        $payload = [
            'trigger_type' => 'welcome',
            'address' => [
                'email' => self::EMAIL,
                'welcome_coupon_code' => 'CODE-WELCOME',
                'welcome_coupon_expires_at' => '2026-11-04',
                'first_order_coupon_code' => 'CODE-FIRST',
                'abandoned_cart_coupon_code' => 'CODE-CART',
            ],
        ];

        self::assertSame(['CODE-WELCOME', 'CODE-FIRST', 'CODE-CART'], $this->codes()->inPayload($payload, self::EMAIL));
    }

    public function testTheAddressMatchesInAnyLetterCase(): void
    {
        $payload = ['address' => ['email' => ' Erase-Test-1@Example.TEST ', 'welcome_coupon_code' => 'CODE-1']];

        self::assertSame(['CODE-1'], $this->codes()->inPayload($payload, self::EMAIL));
    }

    /**
     * A row that only mentions the address somewhere else, or names an
     * address that merely contains it or differs by an accent, is another
     * shopper's code.
     */
    public function testAnotherShoppersCodeIsNeverRead(): void
    {
        $codes = $this->codes();
        foreach (['x' . self::EMAIL, 'erase-tèst-1@example.test', 'erase-test-2@example.test'] as $other) {
            self::assertSame(
                [],
                $codes->inPayload(['address' => ['email' => $other, 'welcome_coupon_code' => 'CODE-1']], self::EMAIL),
                $other
            );
        }
        self::assertSame([], $codes->inPayload(
            ['note' => self::EMAIL, 'address' => ['welcome_coupon_code' => 'CODE-1']],
            self::EMAIL
        ));
    }

    public function testARowWithoutACodeOrAddressGivesNone(): void
    {
        $codes = $this->codes();

        self::assertSame([], $codes->inPayload(null, self::EMAIL));
        self::assertSame([], $codes->inPayload(['contact' => ['email' => self::EMAIL]], self::EMAIL));
        self::assertSame([], $codes->inPayload(
            ['address' => ['email' => self::EMAIL, 'welcome_coupon_code' => '']],
            self::EMAIL
        ));
    }

    public function testNoCodesTouchNoTable(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $codes = new ShopperCodes($resource);

        self::assertSame([], $codes->export([]));
        self::assertSame(['removed' => 0, 'anonymised' => 0], $codes->erase([]));
    }

    private function codes(): ShopperCodes
    {
        return new ShopperCodes($this->createMock(ResourceConnection::class));
    }
}
