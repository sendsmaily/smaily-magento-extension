<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Setup;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\ClientFactory;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Setup\Patch\Data\RecordEngineConnectionAccount;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-237: a Campaign Intelligence connection made before it recorded its
 * Smaily account gets it from the store-wide account, else from the
 * default website's (website 2 here) — one row at the default scope; a
 * second run changes nothing, and a store that is not connected gets
 * nothing.
 */
class RecordEngineConnectionAccountTest extends IntegrationTestCase
{
    public function testAConnectedStoreRecordsTheStoreWideAccount(): void
    {
        $this->connect();
        $this->seed('default', 0, Config::XML_PATH_SUBDOMAIN, 'https://Shop.sendsmaily.net/');
        $this->seed('websites', 2, Config::XML_PATH_SUBDOMAIN, 'shop-lv');
        $before = $this->rows();

        $this->apply();
        $this->apply();

        self::assertSame(
            array_merge($before, [['default', '0', Settings::XML_PATH_SMAILY_SUBDOMAIN, 'shop']]),
            $this->rows()
        );
    }

    public function testWithoutAStoreWideAccountTheDefaultWebsitesIsRecorded(): void
    {
        $this->connect();
        $this->seed('websites', 1, Config::XML_PATH_SUBDOMAIN, 'shop-ee');
        $this->seed('websites', 2, Config::XML_PATH_SUBDOMAIN, 'Shop-LV');

        $this->apply();

        self::assertSame('shop-lv', $this->recorded());
    }

    public function testARecordedAccountStays(): void
    {
        $this->connect();
        $this->seed('default', 0, Config::XML_PATH_SUBDOMAIN, 'shop');
        $this->seed('default', 0, Settings::XML_PATH_SMAILY_SUBDOMAIN, 'shop-engine');

        $this->apply();

        self::assertSame('shop-engine', $this->recorded());
    }

    public function testAStoreThatIsNotConnectedGetsNothing(): void
    {
        $this->seed('default', 0, Config::XML_PATH_SUBDOMAIN, 'shop');
        $before = $this->rows();

        $this->apply();

        self::assertSame($before, $this->rows());
    }

    private function apply(): void
    {
        $settings = $this->objectManager->create(Settings::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, []),
            'configWriter' => $this->objectManager->get(WriterInterface::class),
            'cacheTypeList' => $this->createMock(TypeListInterface::class),
        ]);
        $storeManager = $this->storeManager();
        $connections = $this->objectManager->create(Connections::class, [
            'storeLayout' => $this->storeLayoutFor($storeManager),
            'storeManager' => $storeManager,
            'config' => $this->objectManager->create(Config::class, [
                'scopeConfig' => new DatabaseScopeConfig($this->connection, []),
            ]),
            'settings' => $settings,
            'client' => $this->createMock(Client::class),
            'clientFactory' => $this->createMock(ClientFactory::class),
        ]);

        (new RecordEngineConnectionAccount($settings, $connections))->apply();
    }

    /**
     * Websites 1 and 2; the default store view (2) is website 2's.
     */
    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([1, 2] as $websiteId) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);
            $websites[] = $website;
        }
        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getId')->willReturn(2);
        $defaultStore->method('getWebsiteId')->willReturn(2);

        return $this->createConfiguredMock(StoreManagerInterface::class, [
            'getWebsites' => $websites,
            'getDefaultStoreView' => $defaultStore,
        ]);
    }

    private function connect(): void
    {
        $this->seed('default', 0, Settings::XML_PATH_CONNECTED, '1');
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $this->seed('default', 0, Settings::XML_PATH_API_KEY, $encryptor->encrypt('sk_live_key'));
    }

    private function recorded(): string
    {
        return (string)$this->connection->fetchOne(
            $this->connection->select()->from('core_config_data', ['value'])
                ->where('scope = ?', 'default')
                ->where('path = ?', Settings::XML_PATH_SMAILY_SUBDOMAIN)
        );
    }

    /**
     * @return array<int, array<int, string>> scope, scope id, path, value — by row
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => array_map('strval', array_values($row)),
            $this->connection->fetchAll(
                $this->connection->select()->from('core_config_data', ['scope', 'scope_id', 'path', 'value'])
                    ->order('config_id')
            )
        );
    }

    private function seed(string $scope, int $scopeId, string $path, string $value): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => $path,
            'value' => $value,
        ]);
    }
}
