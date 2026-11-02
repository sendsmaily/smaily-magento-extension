<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Customer\Model\Config\Share;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Websites;

/**
 * CON-253 (contract v1.21.0): the website codes the customer, order and
 * storefront rows carry, and the `store_config` the setup exchange and the
 * history import's `started` call report.
 */
class WebsitesTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function accountShareSettings(): array
    {
        return [
            'Per Website' => ['1', 'website'],
            'Global' => ['0', 'global'],
            'not saved (Magento\'s default is Per Website, read from config.xml)' => [1, 'website'],
        ];
    }

    #[DataProvider('accountShareSettings')]
    public function testTheStoreConfigNamesHowMagentoSharesCustomerAccounts(mixed $saved, string $scope): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->with(Share::XML_PATH_CUSTOMER_ACCOUNT_SHARE)->willReturn($saved);

        $websites = new Websites($this->createMock(StoreManagerInterface::class), $scopeConfig);

        self::assertSame(['customer_account_scope' => $scope], $websites->storeConfig());
    }

    public function testAStoreViewNamesTheCodeOfItsWebsite(): void
    {
        $websites = new Websites($this->storeManager(), $this->createMock(ScopeConfigInterface::class));

        self::assertSame('base', $websites->codeOfStore(1));
        self::assertSame('lv', $websites->codeOfStore(4));
        self::assertSame('lv', $websites->code(2));
    }

    public function testAMissingStoreViewOrWebsiteAndTheAdminWebsiteHaveNoCode(): void
    {
        $websites = new Websites($this->storeManager(), $this->createMock(ScopeConfigInterface::class));

        self::assertNull($websites->codeOfStore(99));
        self::assertNull($websites->codeOfStore(0));
        self::assertNull($websites->code(0));
        self::assertNull($websites->code(9));
    }

    public function testACodeLongerThanTheEngineTakesIsNotSent(): void
    {
        $websites = new Websites($this->storeManager(), $this->createMock(ScopeConfigInterface::class));

        self::assertNull($websites->code(3));
    }

    public function testTheCurrentWebsiteIsTheCurrentStoreViews(): void
    {
        $storeManager = $this->storeManager();
        $websites = new Websites($storeManager, $this->createMock(ScopeConfigInterface::class));

        self::assertSame('lv', $websites->current());
    }

    /**
     * Store views 1 (website 1, `base`) and 4 (website 2, `lv`, the current
     * one); website 3's code is 65 characters; any other id is not found.
     */
    private function storeManager(): StoreManagerInterface
    {
        $stores = [1 => 1, 4 => 2];
        $codes = [1 => 'base', 2 => 'lv', 3 => str_repeat('w', 65)];
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            function (mixed $storeId = null) use ($stores): StoreInterface {
                $storeId ??= 4;
                if (!isset($stores[$storeId])) {
                    throw new NoSuchEntityException(__('The store that was requested wasn\'t found.'));
                }
                $store = $this->createMock(StoreInterface::class);
                $store->method('getWebsiteId')->willReturn($stores[$storeId]);

                return $store;
            }
        );
        $storeManager->method('getWebsite')->willReturnCallback(
            function (mixed $websiteId) use ($codes): WebsiteInterface {
                if (!isset($codes[$websiteId])) {
                    throw new NoSuchEntityException(__('The website that was requested wasn\'t found.'));
                }
                $website = $this->createMock(WebsiteInterface::class);
                $website->method('getCode')->willReturn($codes[$websiteId]);

                return $website;
            }
        );

        return $storeManager;
    }
}
