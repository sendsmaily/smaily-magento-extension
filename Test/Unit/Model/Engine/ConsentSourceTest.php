<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\ConsentSource;

/**
 * One store view without Magento's cookie restriction mode is a store view
 * whose visitors are never asked, so the admin keeps recommending a
 * consent source until every store view has it on (PRO-3664) — or sells on
 * a separate storefront, whose own consent banner decides (PRO-3918).
 */
class ConsentSourceTest extends TestCase
{
    /**
     * @param array<int, bool> $restrictionByStore
     * @dataProvider storesProvider
     */
    public function testCookieRestrictionCountsOnlyWhenEveryStoreViewHasIt(
        array $restrictionByStore,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            $this->createConsentSource($restrictionByStore, [])->isCookieRestrictionOnEverywhere()
        );
    }

    /**
     * @return array<string, array{array<int, bool>, bool}>
     */
    public static function storesProvider(): array
    {
        return [
            'on in every store view' => [[1 => true, 2 => true], true],
            'off in one store view' => [[1 => true, 2 => false], false],
            'off everywhere' => [[1 => false], false],
        ];
    }

    /**
     * @param array<int, bool> $restrictionByStore
     * @param array<int, string> $storefrontByStore
     * @dataProvider missingProvider
     */
    public function testAConsentSourceIsMissingOnlyWhereShoppersBrowseMagentosOwnPages(
        array $restrictionByStore,
        array $storefrontByStore,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            $this->createConsentSource($restrictionByStore, $storefrontByStore)->isMissing()
        );
    }

    /**
     * @return array<string, array{array<int, bool>, array<int, string>, bool}>
     */
    public static function missingProvider(): array
    {
        return [
            'on in every store view' => [[1 => true, 2 => true], [], false],
            'off in one store view' => [[1 => true, 2 => false], [], true],
            'off where a Storefront URL is saved' => [[1 => false], [1 => 'https://shop.example.com'], false],
            'off in a store view without one, beside one with one' => [
                [1 => false, 2 => false],
                [1 => 'https://shop.example.com'],
                true,
            ],
            'on where a Storefront URL is saved' => [[1 => true], [1 => 'https://shop.example.com'], false],
        ];
    }

    /**
     * @param array<int, bool> $restrictionByStore
     * @param array<int, string> $storefrontByStore
     */
    private function createConsentSource(array $restrictionByStore, array $storefrontByStore): ConsentSource
    {
        $stores = [];
        foreach (array_keys($restrictionByStore) as $storeId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static function (string $path, string $scope, int $storeId) use ($restrictionByStore): bool {
                return $path === 'web/cookie/cookie_restriction'
                    && $scope === ScopeInterface::SCOPE_STORE
                    && $restrictionByStore[$storeId];
            }
        );

        $config = $this->createMock(Config::class);
        $config->method('getStorefrontUrl')->willReturnCallback(
            static fn (int $storeId): string => $storefrontByStore[$storeId] ?? ''
        );

        return new ConsentSource($scopeConfig, $storeManager, $config);
    }
}
