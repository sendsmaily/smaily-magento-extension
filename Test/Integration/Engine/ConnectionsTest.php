<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\ClientFactory;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-237 over the real core_config_data and ConfigStorageWriter: a
 * website's own engine connection lives at its website scope, holds a
 * value of its own for every path, reads only its scope and is removed as
 * a whole; Engine\Connections reads the layout (CON-279), the websites'
 * Smaily accounts and the connections from the same rows.
 *
 * Websites 1 (store view 1, the installation's default) and 2 (store
 * views 2 and 3, default 2).
 */
class ConnectionsTest extends IntegrationTestCase
{
    private const STORE_WEBSITES = [1 => 1, 2 => 2, 3 => 2];

    public function testAWebsiteConnectionWritesEveryPathAtItsOwnScope(): void
    {
        $this->settings()->storeExchange(['api_key' => 'sk_live_ee', 'tenant_id' => 't_1'], 'shop');
        // The primary is refused, has pushed workflows and tracks browsing.
        $this->seed('default', 0, [
            Settings::XML_PATH_REFUSED_AT => '2026-09-01 08:00:00',
            Settings::XML_PATH_EMAIL_WORKFLOWS => '{"plugin.welcome":{"workflow_id":456,"subdomain":"shop"}}',
            Settings::XML_PATH_BROWSE_TRACKING => '1',
        ]);

        $this->settings()->withWebsite(2)->storeExchange([
            'api_key' => 'sk_live_lv',
            'tenant_id' => 't_2',
            'tenant_name' => 'Shop LV',
            'smaily_subdomain' => 'shop-lv',
        ], 'shop-lv');

        $website = $this->rows('websites', 2);
        $paths = array_keys($website);
        sort($paths);
        $expected = Settings::WEBSITE_PATHS;
        sort($expected);
        self::assertSame($expected, $paths, 'A value of its own for every path');
        self::assertSame('', $website[Settings::XML_PATH_REFUSED_AT]);
        self::assertSame('', $website[Settings::XML_PATH_EMAIL_WORKFLOWS]);
        self::assertSame('', $website[Settings::XML_PATH_BROWSE_TRACKING]);
        self::assertSame('1', $website[Settings::XML_PATH_WEBSITE_CONNECTION]);

        // A fresh request reads the website's own values back.
        $latvia = $this->settings()->withWebsite(2);
        self::assertTrue($latvia->isConnected());
        self::assertFalse($latvia->isRefused());
        self::assertFalse($latvia->isBrowseTrackingEnabled());
        self::assertSame('sk_live_lv', $latvia->getApiKey());
        self::assertSame('Shop LV', $latvia->getTenantName());
        self::assertSame('shop-lv', $latvia->getSmailySubdomain());
        self::assertFalse($this->settings()->withWebsite(1)->isConnected(), 'Website 1 holds no connection');

        $primary = $this->settings();
        self::assertSame('sk_live_ee', $primary->getApiKey());
        self::assertSame('shop', $primary->getSmailySubdomain());
        self::assertTrue($primary->isBrowseTrackingEnabled());
        self::assertTrue($primary->isRefused());
        self::assertArrayNotHasKey(Settings::XML_PATH_WEBSITE_CONNECTION, $this->rows('default', 0));
    }

    public function testClearingAWebsiteConnectionRemovesItsScopeOnly(): void
    {
        $this->settings()->storeExchange(['api_key' => 'sk_live_ee', 'tenant_id' => 't_1'], 'shop');
        $this->settings()->withWebsite(2)->storeExchange(['api_key' => 'sk_live_lv', 'tenant_id' => 't_2'], 'shop-lv');
        $this->seed('websites', 2, [Config::XML_PATH_SUBDOMAIN => 'shop-lv']);

        $this->settings()->withWebsite(2)->clear();

        self::assertSame([Config::XML_PATH_SUBDOMAIN => 'shop-lv'], $this->rows('websites', 2));
        self::assertFalse($this->settings()->withWebsite(2)->isConnected());
        self::assertTrue($this->settings()->isConnected());
        self::assertSame('t_1', $this->settings()->getTenantId());
    }

    public function testOneStoreIsServedByThePrimary(): void
    {
        $this->settings()->storeExchange(['api_key' => 'sk_live_ee', 'tenant_id' => 't_1'], 'shop');
        $this->seed('default', 0, [Config::XML_PATH_SUBDOMAIN => 'shop', Config::XML_PATH_USERNAME => 'api']);

        $connections = $this->connections();

        self::assertCount(1, $connections->all());
        self::assertSame([1, 2], $connections->primary()->websiteIds());
        self::assertSame([1, 2, 3], $connections->primary()->storeIds());
        self::assertSame(1, $connections->primary()->canonicalStoreId());
        self::assertSame($connections->primary(), $connections->forStore(3));
    }

    public function testSeparateStoresAreServedByTheConnectionOfTheirAccount(): void
    {
        $this->seed('default', 0, [StoreLayout::XML_PATH_SEPARATE_STORES => '1']);
        $this->seed('websites', 1, [Config::XML_PATH_SUBDOMAIN => 'shop', Config::XML_PATH_USERNAME => 'api']);
        $this->seed('websites', 2, [Config::XML_PATH_SUBDOMAIN => 'Shop-LV', Config::XML_PATH_USERNAME => 'api']);
        $this->settings()->storeExchange(['api_key' => 'sk_live_ee', 'tenant_id' => 't_1'], 'shop');

        $connections = $this->connections();
        self::assertSame([1], $connections->primary()->websiteIds());
        self::assertNull($connections->forWebsite(2), 'Not connected before its own connect');

        $this->settings()->withWebsite(2)->storeExchange(['api_key' => 'sk_live_lv', 'tenant_id' => 't_2'], 'shop-lv');
        $connections = $this->connections();
        $latvia = $connections->forWebsite(2);

        self::assertNotNull($latvia);
        self::assertSame(2, $latvia->key());
        self::assertSame([2, 3], $latvia->storeIds());
        self::assertSame(2, $latvia->canonicalStoreId());
        self::assertSame('t_2', $latvia->settings()->getTenantId());
        self::assertSame($latvia, $connections->forRowStoreId(3));
        self::assertSame($connections->primary(), $connections->forRowStoreId(null));
        self::assertSame($connections->primary(), $connections->forStore(1));
    }

    private function settings(): Settings
    {
        return $this->objectManager->create(Settings::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, self::STORE_WEBSITES),
            'configWriter' => $this->objectManager->get(WriterInterface::class),
            'cacheTypeList' => $this->createMock(TypeListInterface::class),
        ]);
    }

    private function connections(): Connections
    {
        $storeManager = $this->storeManager();

        return $this->objectManager->create(Connections::class, [
            'storeLayout' => $this->storeLayoutFor($storeManager),
            'storeManager' => $storeManager,
            'config' => $this->objectManager->create(Config::class, [
                'scopeConfig' => new DatabaseScopeConfig($this->connection, self::STORE_WEBSITES),
            ]),
            'settings' => $this->settings(),
            'client' => $this->createMock(Client::class),
            'clientFactory' => $this->createMock(ClientFactory::class),
        ]);
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([1, 2] as $websiteId) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getDefaultGroupId')->willReturn($websiteId);
            $websites[$websiteId] = $website;
        }
        $stores = [];
        foreach (self::STORE_WEBSITES as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn(array_values($websites));
        $storeManager->method('getStores')->willReturn(array_values($stores));
        $storeManager->method('getDefaultStoreView')->willReturn($stores[1]);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (mixed $websiteId): WebsiteInterface => $websites[(int)$websiteId]
        );
        $storeManager->method('getGroup')->willReturnCallback(
            function (mixed $groupId): GroupInterface {
                $group = $this->createMock(GroupInterface::class);
                $group->method('getDefaultStoreId')->willReturn((int)$groupId);

                return $group;
            }
        );

        return $storeManager;
    }

    /**
     * @return array<string, string> path => value of one scope's rows
     */
    private function rows(string $scope, int $scopeId): array
    {
        return array_map('strval', $this->connection->fetchPairs(
            $this->connection->select()->from('core_config_data', ['path', 'value'])
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
        ));
    }

    /**
     * @param array<string, string> $values path => value
     */
    private function seed(string $scope, int $scopeId, array $values): void
    {
        foreach ($values as $path => $value) {
            $this->connection->insert('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ]);
        }
    }
}
