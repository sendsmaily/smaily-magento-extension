<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Migration;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Setup\Patch\Data\MigrateLegacyConfig;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;

/**
 * The 2.8.x -> v3 settings migration against real core_config_data rows:
 * the DB-level counterpart of the scripted sandbox procedure in TESTING.md.
 */
class MigrateLegacyConfigTest extends IntegrationTestCase
{
    private const LEGACY_CRON_PATH = 'crontab/default/jobs/smaily_subscriber_sync/schedule/cron_expr';

    public function testLegacyDefaultScopeSettingsCarryOverToV3Paths(): void
    {
        $this->seedLegacyRows('default', 0, [
            'smaily/general/subdomain' => 'https://demo.sendsmaily.net',
            'smaily/general/username' => 'api-user',
            'smaily/general/password' => 'plain-secret',
            'smaily/subscribe/enableNewsletterSubscriptions' => '1',
            'smaily/subscribe/workflowId' => '55',
            'smaily/sync/enableCronSync' => '1',
            'smaily/sync/fields' => 'first_name,last_name,gender,bogus_field',
            'smaily/sync/frequency' => '0 */4 * * *',
            'smaily/abandoned/enableAbandonedCart' => '1',
            'smaily/abandoned/autoresponderId' => '77',
            'smaily/abandoned/syncTime' => '2:hour',
            self::LEGACY_CRON_PATH => '0 4 * * *',
        ]);

        $this->applyPatch();

        $config = $this->configValues('default', 0);
        self::assertSame('demo', $config[Config::XML_PATH_SUBDOMAIN], 'Subdomain is normalized');
        self::assertSame('api-user', $config[Config::XML_PATH_USERNAME]);
        self::assertSame('1', $config[Config::XML_PATH_WELCOME_ENABLED]);
        self::assertSame('55', $config[Config::XML_PATH_WELCOME_WORKFLOW]);
        self::assertSame('1', $config[Config::XML_PATH_SYNC_ENABLED]);
        self::assertSame(
            'first_name,last_name,user_gender',
            $config[Config::XML_PATH_SYNC_FIELDS],
            'Unknown fields dropped; the legacy gender tick lands on the v3 field id'
        );
        self::assertSame('1', $config[Config::XML_PATH_ABANDONED_ENABLED]);
        self::assertSame('77', $config[Config::XML_PATH_ABANDONED_WORKFLOW]);
        self::assertSame('120', $config[Config::XML_PATH_ABANDONED_CUTOFF], '"2:hour" becomes minutes');
    }

    public function testLegacyPlaintextPasswordIsEncryptedAndDecryptsBack(): void
    {
        $this->seedLegacyRows('default', 0, ['smaily/general/password' => 'plain-secret']);

        $this->applyPatch();

        $stored = $this->configValues('default', 0)[Config::XML_PATH_PASSWORD];
        self::assertNotSame('plain-secret', $stored, 'Password must not stay in plain text');
        self::assertMatchesRegularExpression(
            '/^\d+:\d+:/',
            $stored,
            'Encrypted value carries the key-version:cipher prefix'
        );

        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        self::assertSame('plain-secret', $encryptor->decrypt($stored));
    }

    public function testEveryLegacyScopeIsMigratedAndMappingsAreSeededPerWebsite(): void
    {
        $this->seedLegacyRows('default', 0, [
            'smaily/subscribe/workflowId' => '55',
            'smaily/abandoned/autoresponderId' => '77',
        ]);
        $this->seedLegacyRows('websites', 2, [
            'smaily/general/subdomain' => 'second',
            'smaily/subscribe/workflowId' => '66',
        ]);

        $this->applyPatch();

        self::assertSame('second', $this->configValues('websites', 2)[Config::XML_PATH_SUBDOMAIN]);

        $mappings = [];
        foreach ($this->fetchAll(MappingResource::TABLE_NAME) as $row) {
            $key = $row['website_id'] . '/' . $row['trigger_type'];
            $mappings[$key] = $row;
        }
        self::assertSame('55', (string)$mappings['0/welcome']['workflow_id']);
        self::assertSame('77', (string)$mappings['0/abandoned_cart']['workflow_id']);
        self::assertSame('66', (string)$mappings['2/welcome']['workflow_id'], 'Website scope seeds its own row');
        self::assertCount(3, $mappings);
        foreach ($mappings as $row) {
            self::assertSame('default', $row['language']);
            self::assertSame('default', $row['account_key']);
            self::assertSame('1', (string)$row['is_default_fallback']);
        }
    }

    public function testLegacyRowsAtEveryScopeAndTheOrphanedCronExpressionAreDeletedAfterTheMigration(): void
    {
        $this->seedLegacyRows('default', 0, [
            'smaily/general/subdomain' => 'demo',
            'smaily/general/password' => 'plain-secret',
            self::LEGACY_CRON_PATH => '0 4 * * *',
        ]);
        $this->seedLegacyRows('websites', 2, ['smaily/general/username' => 'second-user']);
        $this->seedLegacyRows('stores', 3, ['smaily/sync/fields' => 'first_name']);

        $this->applyPatch();

        $paths = array_column($this->fetchAll('core_config_data', 'config_id'), 'path');
        self::assertSame([], preg_grep('#^smaily/#', $paths), 'No 2.8.x row is left at any scope');
        self::assertNotContains(self::LEGACY_CRON_PATH, $paths, 'Orphaned dynamic cron row is removed');
        self::assertSame('demo', $this->configValues('default', 0)[Config::XML_PATH_SUBDOMAIN]);
        self::assertSame('second-user', $this->configValues('websites', 2)[Config::XML_PATH_USERNAME]);
        self::assertNotEmpty($this->configValues('default', 0)[Config::XML_PATH_PASSWORD]);
    }

    public function testDeletingTheLegacyRowsLeavesNeighbouringPathsAlone(): void
    {
        $this->seedLegacyRows('default', 0, [
            'smaily/general/subdomain' => 'demo',
            Config::XML_PATH_USERNAME => 'v3-user',
            'smailyX/general/subdomain' => 'other-module',
            'smaily_other/general/subdomain' => 'another-module',
        ]);

        $this->applyPatch();

        $config = $this->configValues('default', 0);
        self::assertArrayNotHasKey('smaily/general/subdomain', $config);
        self::assertSame('v3-user', $config[Config::XML_PATH_USERNAME]);
        self::assertSame('other-module', $config['smailyX/general/subdomain']);
        self::assertSame('another-module', $config['smaily_other/general/subdomain']);
    }

    public function testAFailedMigrationKeepsTheLegacyRows(): void
    {
        $this->seedLegacyRows('default', 0, ['smaily/general/password' => 'plain-secret']);

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willThrowException(new \RuntimeException('no key'));
        try {
            $this->applyPatch(['encryptor' => $encryptor]);
            self::fail('The migration error must surface');
        } catch (\RuntimeException) {
            // Expected: setup:upgrade stops on the error.
        }

        self::assertSame('plain-secret', $this->configValues('default', 0)['smaily/general/password']);
    }

    public function testDeliberateDropsSurfaceAdminNotices(): void
    {
        $this->seedLegacyRows('default', 0, [
            'smaily/sync/frequency' => '0 */4 * * *',
            'smaily/subscribe/enableCaptcha' => '1',
        ]);

        $this->applyPatch();

        $notices = $this->env->getNotifier()->getNotifications();
        $descriptions = implode(' | ', array_column($notices, 'description'));
        self::assertStringContainsString('sync frequency is no longer configurable', $descriptions);
        self::assertStringContainsString('reCAPTCHA', $descriptions);
    }

    public function testRunningThePatchTwiceIsIdempotent(): void
    {
        $this->seedLegacyRows('default', 0, [
            'smaily/general/subdomain' => 'demo',
            'smaily/subscribe/workflowId' => '55',
        ]);

        $this->applyPatch();
        $this->applyPatch();

        self::assertSame('demo', $this->configValues('default', 0)[Config::XML_PATH_SUBDOMAIN]);
        $configRows = $this->connection->fetchAll(
            $this->connection->select()->from('core_config_data')
                ->where('path = ?', Config::XML_PATH_SUBDOMAIN)
        );
        self::assertCount(1, $configRows, 'Config writer must update, not duplicate');
        self::assertCount(1, $this->fetchAll(MappingResource::TABLE_NAME), 'Mapping seed must not duplicate');
    }

    public function testFreshInstallWithoutLegacyRowsIsANoOp(): void
    {
        $this->applyPatch();

        self::assertSame([], $this->fetchAll('core_config_data', 'config_id'));
        self::assertSame([], $this->fetchAll(MappingResource::TABLE_NAME));
        self::assertSame([], $this->env->getNotifier()->getNotifications());
    }

    /**
     * @param array<string, mixed> $arguments constructor overrides
     */
    private function applyPatch(array $arguments = []): void
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        /** @var MigrateLegacyConfig $patch */
        $patch = $this->objectManager->create(MigrateLegacyConfig::class, $arguments + [
            'moduleDataSetup' => new DataSetup($resourceConnection),
        ]);
        $patch->apply();
    }

    /**
     * @param array<string, string> $values path => value
     */
    private function seedLegacyRows(string $scope, int $scopeId, array $values): void
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

    /**
     * All config values of one scope, keyed by path.
     *
     * @return array<string, string|null>
     */
    private function configValues(string $scope, int $scopeId): array
    {
        $rows = $this->connection->fetchAll(
            $this->connection->select()->from('core_config_data')
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(string)$row['path']] = $row['value'] === null ? null : (string)$row['value'];
        }

        return $values;
    }
}
