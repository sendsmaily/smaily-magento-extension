<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\FlagManager;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Model\OrderPlacer;

/**
 * PRO-3660 (owner decision 2026-10-02): an order placed through GraphQL, or
 * through REST without Magento's storefront `form_key` cookie, is an API
 * order; every other order a shopper places is a storefront order. An order
 * an admin places outside the API — in the admin's order screen or with
 * "Login as Customer" — is neither (PRO-3949). The Storefront URL field opens
 * when the last 30 days had API orders and no storefront order.
 */
class OrderOriginTest extends TestCase
{
    private const NOW = 1790000000;

    /** @var array<string, mixed> */
    private array $flags = [];

    /**
     * @dataProvider originProvider
     */
    public function testAnOrderIsStampedByWhereItCameFrom(
        ?string $area,
        ?string $formKey,
        ?int $loggedAsCustomerAdminId,
        ?string $expectedFlag
    ): void {
        $this->orderOrigin($area, $formKey, $loggedAsCustomerAdminId)->record();

        self::assertSame($expectedFlag === null ? [] : [$expectedFlag => self::NOW], $this->flags);
    }

    /**
     * Area, form key cookie, the "Login as Customer" admin id (null: the
     * module is off), the flag stamped (null: none).
     *
     * @return array<string, array{?string, ?string, ?int, ?string}>
     */
    public static function originProvider(): array
    {
        $api = OrderOrigin::FLAG_LAST_API_ORDER;
        $storefront = OrderOrigin::FLAG_LAST_STOREFRONT_ORDER;

        return [
            'GraphQL' => ['graphql', null, null, $api],
            'GraphQL with a form key cookie' => ['graphql', 'abc', null, $api],
            'REST without a form key cookie' => ['webapi_rest', null, null, $api],
            'REST with an empty form key cookie' => ['webapi_rest', '', null, $api],
            'REST from a Magento page (Luma checkout)' => ['webapi_rest', 'abc', null, $storefront],
            'REST from a Magento page, Login as Customer on, a shopper' => ['webapi_rest', 'abc', 0, $storefront],
            'storefront controller' => ['frontend', null, null, $storefront],
            'no area' => [null, null, null, $storefront],
            'admin order screen' => ['adminhtml', null, null, null],
            'admin order screen with a form key cookie' => ['adminhtml', 'abc', 0, null],
            'Login as Customer, Luma checkout' => ['webapi_rest', 'abc', 7, null],
            'Login as Customer, storefront controller' => ['frontend', null, 7, null],
            'GraphQL while logged in as the customer' => ['graphql', null, 7, $api],
            'REST without a form key cookie while logged in as the customer' => ['webapi_rest', null, 7, $api],
        ];
    }

    /**
     * @dataProvider windowProvider
     */
    public function testTheFieldOpensOnlyWhenTheLast30DaysHadApiOrdersAndNoStorefrontOrder(
        ?int $apiAgo,
        ?int $storefrontAgo,
        bool $expected
    ): void {
        $day = 86400;
        if ($apiAgo !== null) {
            $this->flags[OrderOrigin::FLAG_LAST_API_ORDER] = self::NOW - $apiAgo * $day;
        }
        if ($storefrontAgo !== null) {
            $this->flags[OrderOrigin::FLAG_LAST_STOREFRONT_ORDER] = self::NOW - $storefrontAgo * $day;
        }

        self::assertSame($expected, $this->orderOrigin('adminhtml', null, null)->isApiOnly());
    }

    /**
     * @return array<string, array{?int, ?int, bool}>
     */
    public static function windowProvider(): array
    {
        return [
            'no order seen yet' => [null, null, false],
            'API orders only' => [2, null, true],
            'API orders, storefront order long ago' => [2, 31, true],
            'API order on the window edge' => [30, null, true],
            'API and storefront orders' => [2, 10, false],
            'storefront orders only' => [null, 1, false],
            'last API order too old' => [31, null, false],
        ];
    }

    private function orderOrigin(?string $area, ?string $formKey, ?int $loggedAsCustomerAdminId): OrderOrigin
    {
        $state = $this->createMock(State::class);
        if ($area === null) {
            $state->method('getAreaCode')->willThrowException(new LocalizedException(new Phrase('Area code is not set')));
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }
        $cookies = $this->createMock(CookieManagerInterface::class);
        $cookies->method('getCookie')->willReturnCallback(
            static fn (string $name) => $name === 'form_key' ? $formKey : null
        );
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('saveFlag')->willReturnCallback(function (string $code, $value): bool {
            $this->flags[$code] = $value;

            return true;
        });
        $flagManager->method('getFlagData')->willReturnCallback(fn (string $code) => $this->flags[$code] ?? null);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW);

        $moduleManager = $this->createMock(ModuleManager::class);
        $moduleManager->method('isEnabled')->with('Magento_LoginAsCustomer')
            ->willReturn($loggedAsCustomerAdminId !== null);
        $getAdminId = new class ((int)$loggedAsCustomerAdminId) {
            public function __construct(private readonly int $adminId)
            {
            }

            public function execute(): int
            {
                return $this->adminId;
            }
        };
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('get')
            ->with('Magento\\LoginAsCustomerApi\\Api\\GetLoggedAsCustomerAdminIdInterface')
            ->willReturn($getAdminId);

        return new OrderOrigin(
            $state,
            $cookies,
            $flagManager,
            $dateTime,
            new OrderPlacer($moduleManager, $objectManager, $state)
        );
    }
}
