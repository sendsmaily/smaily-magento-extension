<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Api\Data\GroupInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\ClientFactory;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * CON-237: which Campaign Intelligence connection serves each website. One
 * store is one engine store — the primary serves every website and nothing
 * else is read; under separate stores a website is served by the
 * connection made for its Smaily account, else by none.
 *
 * Websites 1 (store views 1, 2), 2 (store views 4, 5; default 4) and 3
 * (store view 6); store view 1 is the installation's default.
 */
class ConnectionsTest extends TestCase
{
    private const WEBSITE_STORES = [1 => [1, 2], 2 => [4, 5], 3 => [6]];
    private const DEFAULT_STORES = [1 => 1, 2 => 4, 3 => 6];

    /** @var array<string, array<int, array<string, string>>> scope => scope id => path => value */
    private array $rows = [];

    /** @var array<int, string> scopes other than the default one that were read */
    private array $scopedReads = [];

    private Client&MockObject $client;

    private ClientFactory&MockObject $clientFactory;

    protected function setUp(): void
    {
        $this->rows = [];
        $this->scopedReads = [];
        $this->client = $this->createMock(Client::class);
        $this->clientFactory = $this->createMock(ClientFactory::class);
    }

    public function testOneStoreIsServedByThePrimaryAndReadsNothingElse(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 2, 'shop-lv');
        $this->websiteConnection(2, 'shop-lv');

        $connections = $this->connections(false);
        $primary = $connections->primary();

        self::assertSame([$primary], $connections->all());
        self::assertTrue($primary->isPrimary());
        self::assertSame(0, $primary->key());
        self::assertSame([1, 2, 3], $primary->websiteIds());
        self::assertSame([1, 2, 4, 5, 6], $primary->storeIds());
        self::assertSame(1, $primary->canonicalStoreId());
        self::assertTrue($primary->servesAllWebsites());
        foreach ([0, 1, 2, 3, 99] as $websiteId) {
            self::assertSame($primary, $connections->forWebsite($websiteId), 'Website ' . $websiteId);
        }
        foreach ([0, 1, 5, 99] as $storeId) {
            self::assertSame($primary, $connections->forStore($storeId), 'Store view ' . $storeId);
        }
        self::assertSame($primary, $connections->forRowStoreId(null));
        self::assertSame($primary, $connections->forKey(0));
        self::assertNull($connections->forKey(2), 'A website connection left over is not served under one store');
        self::assertSame([], $this->scopedReads, 'One store reads no website scope');
    }

    public function testANotConnectedPrimaryStillServesOneStore(): void
    {
        $connections = $this->connections(false);

        self::assertSame($connections->primary(), $connections->forWebsite(2));
        self::assertFalse($connections->primary()->settings()->isConnected());
    }

    public function testSeparateStoresAreServedByTheConnectionMadeForTheirAccount(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 1, 'shop');
        $this->account('websites', 2, 'https://Shop-LV.sendsmaily.net/');
        $this->account('websites', 3, 'shop-fi');
        $this->websiteConnection(2, 'shop-lv');

        $connections = $this->connections(true);
        $primary = $connections->primary();
        $latvia = $connections->forKey(2);

        self::assertNotNull($latvia);
        self::assertSame([$primary, $latvia], $connections->all());
        self::assertSame($primary, $connections->forWebsite(1));
        self::assertSame($latvia, $connections->forWebsite(2));
        self::assertNull($connections->forWebsite(3), 'No connection was made for its account');
        self::assertSame([1], $primary->websiteIds());
        self::assertSame([1, 2], $primary->storeIds());
        self::assertSame(1, $primary->canonicalStoreId());
        self::assertFalse($primary->servesAllWebsites());
        self::assertFalse($latvia->isPrimary());
        self::assertSame([2], $latvia->websiteIds());
        self::assertSame([4, 5], $latvia->storeIds());
        self::assertSame(4, $latvia->canonicalStoreId());
        self::assertSame(2, $latvia->settings()->getWebsiteId());
        self::assertSame('t_2', $latvia->settings()->getTenantId());
        self::assertSame('t_1', $primary->settings()->getTenantId());

        self::assertSame($latvia, $connections->forStore(5));
        self::assertNull($connections->forStore(6));
        self::assertNull($connections->forStore(99), 'A deleted store view is not routed');
        self::assertSame($primary, $connections->forStore(0));
        self::assertSame($primary, $connections->forRowStoreId(null), 'A row queued before CON-237');
        self::assertSame($latvia, $connections->forRowStoreId(4));
        self::assertSame($primary, $connections->forWebsite(0));
        self::assertNull($connections->forKey(3));
    }

    public function testTwoWebsitesOnOneAccountShareOneConnection(): void
    {
        $this->account('websites', 1, 'shop');
        $this->account('websites', 2, 'shop-baltic');
        $this->account('websites', 3, 'shop-baltic');
        // Connected through website 3: website 2 shares it.
        $this->websiteConnection(3, 'shop-baltic');

        $connections = $this->connections(true);
        $baltic = $connections->forWebsite(2);

        self::assertNotNull($baltic);
        self::assertSame($baltic, $connections->forWebsite(3));
        self::assertSame(3, $baltic->key());
        self::assertSame([2, 3], $baltic->websiteIds());
        self::assertSame([4, 5, 6], $baltic->storeIds());
        self::assertSame(4, $baltic->canonicalStoreId(), 'The lowest website\'s default store view');
        self::assertNull($connections->forWebsite(1));
    }

    public function testAWebsiteInPerLanguageAccountsModeIsGroupedByItsFallbackAccount(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 1, 'shop');
        // Mode A: the website's account is its fallback account; its store
        // views send with per-language accounts of their own.
        $this->account('websites', 2, 'shop-lv');
        $this->rows['websites'][2][Config::XML_PATH_MULTILINGUAL_MODE] = 'a';
        $this->account('stores', 4, 'shop-ru');
        $this->account('stores', 5, 'shop-lv');
        $this->websiteConnection(2, 'shop-lv');

        $connections = $this->connections(true);

        self::assertSame(2, $connections->forWebsite(2)?->key());
        self::assertSame(2, $connections->forStore(4)?->key(), 'Every store view of the website');
        self::assertSame('shop-lv', $connections->accountOf(2));
    }

    /**
     * CON-192: websites on different accounts of their own and no
     * store-wide one, connected before CON-237 — the patch recorded the
     * default website's account for the primary.
     */
    public function testAStoreWithSeparateAccountsAndNoStoreWideOneKeepsThePrimaryForTheDefaultWebsite(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 1, 'shop');
        $this->account('websites', 2, 'shop-lv');
        $this->account('websites', 3, 'shop');

        $connections = $this->connections(true);

        self::assertSame([1, 3], $connections->primary()->websiteIds());
        self::assertSame(1, $connections->primary()->canonicalStoreId());
        self::assertNull($connections->forWebsite(2));
        self::assertSame('shop', $connections->installationAccount());
    }

    public function testWithoutAConnectedPrimaryOnlyWebsiteConnectionsServe(): void
    {
        // A disconnected primary's account claims nothing.
        $this->rows['default'][0][Settings::XML_PATH_SMAILY_SUBDOMAIN] = 'shop';
        $this->account('websites', 1, 'shop');
        $this->account('websites', 2, 'shop-lv');
        $this->websiteConnection(2, 'shop-lv');

        $connections = $this->connections(true);
        $primary = $connections->primary();

        self::assertNull($connections->forWebsite(1));
        self::assertSame(2, $connections->forWebsite(2)?->key());
        self::assertSame([], $primary->websiteIds());
        self::assertSame([], $primary->storeIds());
        self::assertNull($primary->canonicalStoreId(), 'Its rows carry NULL, the primary');
        self::assertFalse($primary->servesAllWebsites());
        self::assertSame($primary, $connections->forRowStoreId(null));
    }

    public function testAWebsiteWithoutASmailyAccountIsNotConnected(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 1, 'shop');

        $connections = $this->connections(true);

        self::assertNull($connections->forWebsite(2));
        self::assertNull($connections->forWebsite(3));
        self::assertSame('', $connections->accountOf(2));
    }

    public function testThePrimaryWinsAnAccountTwoConnectionsName(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 1, 'shop');
        $this->account('websites', 2, 'shop');
        $this->websiteConnection(2, 'shop');

        $connections = $this->connections(true);

        self::assertSame([1, 2], $connections->primary()->websiteIds());
        self::assertFalse($connections->primary()->servesAllWebsites());
        self::assertSame([], $connections->forKey(2)?->websiteIds());
    }

    public function testEveryWebsiteOnThePrimarysAccountMeansItServesAll(): void
    {
        $this->connectPrimary('shop');
        foreach ([1, 2, 3] as $websiteId) {
            $this->account('websites', $websiteId, 'shop');
        }

        self::assertTrue($this->connections(true)->primary()->servesAllWebsites());
    }

    public function testThePrimaryUsesTheDiClientAndAWebsiteConnectionAClientOfItsOwn(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 2, 'shop-lv');
        $this->websiteConnection(2, 'shop-lv');
        $own = $this->createMock(Client::class);
        $this->clientFactory->expects(self::once())->method('create')
            ->with(self::callback(
                static fn (array $data): bool => $data['settings'] instanceof Settings
                    && $data['settings']->getWebsiteId() === 2
            ))
            ->willReturn($own);

        $connections = $this->connections(true);
        $latvia = $connections->forKey(2);
        self::assertInstanceOf(Connection::class, $latvia);

        self::assertSame($this->client, $connections->client($connections->primary()));
        self::assertSame($own, $connections->client($latvia));
        self::assertSame($own, $connections->client($latvia), 'One client per connection and request');
    }

    public function testTheInstallationAccountIsTheStoreWideOneElseTheDefaultWebsites(): void
    {
        $this->account('websites', 1, 'Shop-EE');
        $this->account('websites', 2, 'shop-lv');

        self::assertSame('shop-ee', $this->connections(true)->installationAccount());

        $this->account('default', 0, 'https://Shop.sendsmaily.net');

        self::assertSame('shop', $this->connections(false)->installationAccount());

        $this->rows = [];

        self::assertSame('', $this->connections(false)->installationAccount());
    }

    public function testItIsBuiltOncePerRequestUntilReset(): void
    {
        $this->connectPrimary('shop');
        $this->account('websites', 2, 'shop-lv');
        $connections = $this->connections(true);
        self::assertNull($connections->forWebsite(2));

        $this->websiteConnection(2, 'shop-lv');

        self::assertNull($connections->forWebsite(2));
        $connections->reset();
        self::assertSame(2, $connections->forWebsite(2)?->key());
    }

    private function connectPrimary(string $account): void
    {
        $this->rows['default'][0] = array_merge($this->rows['default'][0] ?? [], [
            Settings::XML_PATH_CONNECTED => '1',
            Settings::XML_PATH_API_KEY => 'encrypted',
            Settings::XML_PATH_TENANT_ID => 't_1',
            Settings::XML_PATH_SMAILY_SUBDOMAIN => $account,
        ]);
    }

    private function websiteConnection(int $websiteId, string $account): void
    {
        $this->rows['websites'][$websiteId] = array_merge($this->rows['websites'][$websiteId] ?? [], [
            Settings::XML_PATH_WEBSITE_CONNECTION => '1',
            Settings::XML_PATH_CONNECTED => '1',
            Settings::XML_PATH_API_KEY => 'encrypted',
            Settings::XML_PATH_TENANT_ID => 't_' . $websiteId,
            Settings::XML_PATH_SMAILY_SUBDOMAIN => $account,
        ]);
    }

    private function account(string $scope, int $scopeId, string $subdomain): void
    {
        $this->rows[$scope][$scopeId][Config::XML_PATH_SUBDOMAIN] = $subdomain;
    }

    private function connections(bool $separateStores): Connections
    {
        $scopeConfig = $this->scopeConfig();
        $storeLayout = $this->createMock(StoreLayout::class);
        $storeLayout->method('isSeparateStores')->willReturn($separateStores);
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('sk_live_key');

        return new Connections(
            $storeLayout,
            $this->storeManager(),
            new Config($scopeConfig, $encryptor),
            new SubdomainNormalizer(),
            new Settings(
                $scopeConfig,
                $this->createMock(WriterInterface::class),
                $encryptor,
                $this->createMock(TypeListInterface::class),
                new Json(),
                $this->createMock(DateTime::class)
            ),
            $this->client,
            $this->clientFactory
        );
    }

    /**
     * core_config_data with Magento's fallback: store view -> website ->
     * default scope.
     */
    private function scopeConfig(): ScopeConfigInterface
    {
        $storeWebsites = [];
        foreach (self::WEBSITE_STORES as $websiteId => $storeIds) {
            foreach ($storeIds as $storeId) {
                $storeWebsites[$storeId] = $websiteId;
            }
        }
        $read = function (string $path, string $scope = 'default', mixed $code = null) use (
            &$read,
            $storeWebsites
        ): ?string {
            $id = (int)$code;
            if ($scope === 'store' || $scope === 'stores') {
                $this->scopedReads[] = 'stores/' . $id;

                return $this->rows['stores'][$id][$path] ?? $read($path, 'websites', $storeWebsites[$id] ?? 0);
            }
            if ($scope === 'website' || $scope === 'websites') {
                $this->scopedReads[] = 'websites/' . $id;

                return $this->rows['websites'][$id][$path] ?? $read($path);
            }

            return $this->rows['default'][0][$path] ?? null;
        };
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback($read);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope = 'default', mixed $code = null): bool
                => (bool)$read($path, $scope, $code)
        );

        return $scopeConfig;
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        $stores = [];
        foreach (self::WEBSITE_STORES as $websiteId => $storeIds) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getDefaultGroupId')->willReturn($websiteId);
            $websites[$websiteId] = $website;
            foreach ($storeIds as $storeId) {
                $store = $this->createMock(StoreInterface::class);
                $store->method('getId')->willReturn($storeId);
                $store->method('getWebsiteId')->willReturn($websiteId);
                $stores[$storeId] = $store;
            }
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
                $group->method('getDefaultStoreId')->willReturn(self::DEFAULT_STORES[(int)$groupId]);

                return $group;
            }
        );

        return $storeManager;
    }
}
