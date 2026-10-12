<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Migration\MigrationOutcome;
use Smaily\Connect\Setup\Patch\Data\ConfirmContactsOfConnectedWebsites;
use Smaily\Connect\Setup\Patch\Data\MigrateLegacyConfig;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * PRO-4036: contact sync waits for a website's Contacts settings to be
 * saved. A store already on a 3.0.0 release candidate synced every website
 * with credentials saved, so the update marks those websites' Contacts
 * settings saved and they keep syncing; a new install, whose websites have
 * no credentials when the patches run, gets nothing marked, and a 2.8.x
 * upgrade in the same setup run is left to the 2.8.x migration.
 */
class ConfirmContactsOfConnectedWebsitesTest extends IntegrationTestCase
{
    private const WEBSITES = [1, 2, 3];

    public function testAReleaseCandidateStoreKeepsSyncingOnEveryWebsiteTheDefaultAccountConnects(): void
    {
        $this->seedAccount('default', 0);

        $this->runSetupPatches();

        foreach (self::WEBSITES as $websiteId) {
            self::assertTrue($this->config()->isContactSyncActive($websiteId), 'Website ' . $websiteId);
        }
    }

    public function testOnlyAWebsiteWithItsOwnCredentialsIsMarked(): void
    {
        $this->seedAccount('websites', 2);
        // Website 3: an account without a password is not connected.
        $this->seed('websites', 3, [Config::XML_PATH_SUBDOMAIN => 'shop', Config::XML_PATH_USERNAME => 'api-user']);

        $this->runSetupPatches();

        self::assertSame([2], $this->markedWebsites());
        self::assertTrue($this->config()->isContactSyncActive(2));
        self::assertFalse($this->config()->isContactSyncActive(3));
    }

    public function testAWebsiteWithContactSyncOffStaysOffAfterTheUpdate(): void
    {
        $this->seedAccount('default', 0);
        $this->seed('websites', 2, [Config::XML_PATH_SYNC_ENABLED => '0']);

        $this->runSetupPatches();

        self::assertFalse($this->config()->isContactSyncActive(2));
        self::assertTrue($this->config()->isContactSyncActive(1));
    }

    public function testANewInstallMarksNothing(): void
    {
        $this->runSetupPatches();

        self::assertSame([], $this->markedWebsites());
        foreach (self::WEBSITES as $websiteId) {
            // Contact sync is on by default, yet nothing syncs before the
            // website's Contacts settings are saved.
            self::assertTrue($this->config()->isSyncEnabled($websiteId));
            self::assertFalse($this->config()->isContactSyncActive($websiteId), 'Website ' . $websiteId);
        }
    }

    /**
     * Upgrading straight from 2.8.x: the migration marks the websites whose
     * 2.8.x sync was on; this patch adds none of the websites it left out,
     * even though their migrated credentials connect them.
     */
    public function testAnUpgradeFrom28xInTheSameRunIsLeftToTheMigration(): void
    {
        $this->seed('default', 0, [
            'smaily/general/subdomain' => 'shop',
            'smaily/general/username' => 'api-user',
            'smaily/general/password' => 'plain-secret',
            'smaily/sync/enableCronSync' => '1',
        ]);
        $this->seed('websites', 2, ['smaily/sync/enableCronSync' => '0']);

        $this->runSetupPatches();

        self::assertSame([1, 3], $this->markedWebsites());
        self::assertFalse($this->config()->isContactSyncActive(2));
    }

    /**
     * The two patches in dependency order, sharing one outcome as the
     * object manager's shared instance does in a real setup run.
     */
    private function runSetupPatches(): void
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $outcome = new MigrationOutcome();
        $storeManager = $this->storeManager();

        $this->objectManager->create(MigrateLegacyConfig::class, [
            'moduleDataSetup' => new DataSetup($resourceConnection),
            'migrationOutcome' => $outcome,
            'storeManager' => $storeManager,
        ])->apply();
        $this->objectManager->create(ConfirmContactsOfConnectedWebsites::class, [
            'config' => $this->config(),
            'storeManager' => $storeManager,
            'migrationOutcome' => $outcome,
        ])->apply();
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach (self::WEBSITES as $websiteId) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getName')->willReturn('Website ' . $websiteId);
            $websites[] = $website;
        }

        return $this->createConfiguredMock(StoreManagerInterface::class, ['getWebsites' => $websites]);
    }

    /**
     * Config read from core_config_data with Magento's scope fallback.
     */
    private function config(): Config
    {
        return $this->objectManager->create(Config::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, []),
        ]);
    }

    /**
     * @return int[] the websites whose Contacts settings are marked saved
     */
    private function markedWebsites(): array
    {
        return array_map('intval', $this->connection->fetchCol(
            $this->connection->select()->from('core_config_data', ['scope_id'])
                ->where('path = ?', Config::XML_PATH_CONTACTS_CONFIRMED)
                ->where('scope = ?', 'websites')
                ->where('value = ?', '1')
                ->order('scope_id')
        ));
    }

    private function seedAccount(string $scope, int $scopeId): void
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $this->seed($scope, $scopeId, [
            Config::XML_PATH_SUBDOMAIN => 'shop',
            Config::XML_PATH_USERNAME => 'api-user',
            Config::XML_PATH_PASSWORD => $encryptor->encrypt('secret'),
        ]);
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
