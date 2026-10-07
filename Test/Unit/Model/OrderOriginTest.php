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
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\OrderOrigin;

/**
 * PRO-3660 (owner decision 2026-10-02): an order placed through GraphQL, or
 * through REST without Magento's storefront `form_key` cookie, is an API
 * order; every other order is a storefront order. The Storefront URL field
 * opens when the last 30 days had API orders and no storefront order.
 */
class OrderOriginTest extends TestCase
{
    private const NOW = 1790000000;

    /** @var array<string, mixed> */
    private array $flags = [];

    /**
     * @dataProvider originProvider
     */
    public function testAnOrderIsStampedByWhereItCameFrom(?string $area, ?string $formKey, string $expectedFlag): void
    {
        $this->orderOrigin($area, $formKey)->record();

        self::assertSame([$expectedFlag => self::NOW], $this->flags);
    }

    /**
     * @return array<string, array{?string, ?string, string}>
     */
    public static function originProvider(): array
    {
        return [
            'GraphQL' => ['graphql', null, OrderOrigin::FLAG_LAST_API_ORDER],
            'GraphQL with a form key cookie' => ['graphql', 'abc', OrderOrigin::FLAG_LAST_API_ORDER],
            'REST without a form key cookie' => ['webapi_rest', null, OrderOrigin::FLAG_LAST_API_ORDER],
            'REST with an empty form key cookie' => ['webapi_rest', '', OrderOrigin::FLAG_LAST_API_ORDER],
            'REST from a Magento page (Luma checkout)' =>
                ['webapi_rest', 'abc', OrderOrigin::FLAG_LAST_STOREFRONT_ORDER],
            'storefront controller' => ['frontend', null, OrderOrigin::FLAG_LAST_STOREFRONT_ORDER],
            'admin order' => ['adminhtml', null, OrderOrigin::FLAG_LAST_STOREFRONT_ORDER],
            'no area' => [null, null, OrderOrigin::FLAG_LAST_STOREFRONT_ORDER],
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

        self::assertSame($expected, $this->orderOrigin('adminhtml', null)->isApiOnly());
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

    private function orderOrigin(?string $area, ?string $formKey): OrderOrigin
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

        return new OrderOrigin($state, $cookies, $flagManager, $dateTime);
    }
}
