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
use Smaily\Connect\Model\Engine\ConsentSource;

/**
 * One store view without Magento's cookie restriction mode is a store view
 * whose visitors are never asked, so the admin keeps recommending a
 * consent source until every store view has it on (PRO-3664).
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

        self::assertSame(
            $expected,
            (new ConsentSource($scopeConfig, $storeManager))->isCookieRestrictionOnEverywhere()
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
}
