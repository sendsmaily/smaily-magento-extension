<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Migration\LegacyConfigMapper;
use Smaily\Connect\Model\Migration\MigrationOutcome;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;

/**
 * Migrates Smaily for Magento <= 2.8.x settings (section "smaily", all
 * scopes) onto the v3 paths so an upgrade keeps working without any manual
 * reconfiguration. The plaintext legacy API password is encrypted; the
 * configured autoresponder IDs are also seeded into the automation mapping
 * table as per-website fallback rows. Once every scope is migrated, the
 * legacy `smaily/*` rows are deleted at every scope (the plaintext password
 * among them): a downgrade to 2.8.x then starts with empty settings. A
 * failure before that point leaves them in place.
 *
 * Enable Module = No turns contact sync, welcome and abandoned cart off at
 * its scope (LegacyConfigMapper). Under a default scope with No, a website
 * with its own Yes also gets, at its own scope, what the three resolved to
 * there in 2.8.x — its own value, else the default scope's, else the
 * config.xml default — so it runs as it did in 2.8.x.
 *
 * Store-view rows: 2.8.x read every setting per website, so it never read a
 * store-view row. v3 reads the Smaily account per store view, so the
 * store-view account rows (and Enable Module) are not carried over, and an
 * admin notice names the store views whose account values were left out.
 * Every other store-view row is carried over at its store view, where v3,
 * like 2.8.x, does not read it.
 */
class MigrateLegacyConfig implements DataPatchInterface
{
    /**
     * Legacy paths (relative to "smaily/") not carried over at store-view scope.
     */
    private const STORE_VIEW_ACCOUNT_PATHS = ['general/subdomain', 'general/username', 'general/password'];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly LegacyConfigMapper $mapper,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly NotifierInterface $notifier,
        private readonly MigrationOutcome $migrationOutcome,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $configTable = $this->moduleDataSetup->getTable('core_config_data');

        $select = $connection->select()
            ->from($configTable, ['scope', 'scope_id', 'path', 'value'])
            ->where('path LIKE ?', 'smaily/%');
        $rows = $connection->fetchAll($select);
        if (!$rows) {
            return $this;
        }

        // Group legacy values per scope; paths become relative to "smaily/".
        $byScope = [];
        foreach ($rows as $row) {
            $scopeKey = $row['scope'] . ':' . $row['scope_id'];
            $relativePath = substr((string)$row['path'], strlen('smaily/'));
            $byScope[$scopeKey][$relativePath] = $row['value'] === null ? null : (string)$row['value'];
        }

        $allNotices = [];
        $storeViewAccountIds = [];
        $defaultLegacy = $byScope['default:0'] ?? [];
        $defaultSwitches = $this->mapper->moduleSwitch($defaultLegacy) === false
            ? $this->mapper->moduleSwitchValues($defaultLegacy)
            : null;
        foreach ($byScope as $scopeKey => $legacy) {
            [$scope, $scopeId] = explode(':', $scopeKey, 2);
            $scope = $scope === 'default' ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : $scope;
            if ($scope === 'stores') {
                $account = array_intersect_key($legacy, array_flip(self::STORE_VIEW_ACCOUNT_PATHS));
                if (array_filter($account, static fn (?string $value): bool => trim((string)$value) !== '')) {
                    $storeViewAccountIds[] = (int)$scopeId;
                }
                unset($legacy['general/enable']);
                $legacy = array_diff_key($legacy, $account);
            }
            $result = $this->mapper->map($legacy);

            foreach ($result['configs'] as $config) {
                $value = in_array(LegacyConfigMapper::FLAG_ENCRYPT, $config['flags'], true)
                    ? $this->encryptor->encrypt($config['value'])
                    : $config['value'];
                $this->configWriter->save($config['path'], $value, $scope, (int)$scopeId);
            }
            if ($scope === 'websites' && $defaultSwitches !== null && $this->mapper->moduleSwitch($legacy) === true) {
                $ownPaths = array_flip(array_column($result['configs'], 'path'));
                foreach (array_diff_key($defaultSwitches, $ownPaths) as $path => $value) {
                    $this->configWriter->save($path, $value, $scope, (int)$scopeId);
                }
            }

            $this->seedAutomationMappings($legacy, $scope, (int)$scopeId);
            $allNotices = array_merge($allNotices, $result['notices']);
        }

        // Every scope is migrated: the 2.8.x rows go, the plaintext password
        // among them. Only "smaily/" itself matches, not smaily_connect/*.
        $connection->delete($configTable, ['path LIKE ?' => 'smaily/%']);

        // The legacy dynamic cron expression row is orphaned in v3.
        $connection->delete($configTable, [
            'path = ?' => 'crontab/default/jobs/smaily_subscriber_sync/schedule/cron_expr',
        ]);

        foreach (array_unique($allNotices) as $notice) {
            $this->notifier->addNotice((string)__('Smaily Connect upgrade'), $notice);
        }
        $this->noticeStoreViewAccounts($storeViewAccountIds);
        // AddSetupNotice, next in this run, says the settings were migrated.
        $this->migrationOutcome->markMigrated();

        return $this;
    }

    /**
     * Name the store views whose store-view account values were not carried
     * over, each with its website (store-view names repeat across websites).
     * A store view that no longer exists has nothing to name.
     *
     * @param int[] $storeIds
     */
    private function noticeStoreViewAccounts(array $storeIds): void
    {
        $names = [];
        foreach ($storeIds as $storeId) {
            try {
                $store = $this->storeManager->getStore($storeId);
                $website = $this->storeManager->getWebsite($store->getWebsiteId());
                $names[] = $store->getName() . ' (' . $website->getName() . ')';
            } catch (NoSuchEntityException) {
                continue;
            }
        }
        if (!$names) {
            return;
        }

        $this->notifier->addNotice(
            (string)__('Smaily Connect upgrade: store-view Smaily account not carried over'),
            (string)__(
                'The Smaily subdomain, API username or API password saved for these store views was not'
                . ' carried over, because Smaily for Magento 2.8.x did not use it: %1.'
                . ' These store views use their website\'s Smaily account, as they did in 2.8.x.',
                implode(', ', $names)
            )
        );
    }

    /**
     * Seed smaily_automation_mapping fallback rows from the legacy workflow
     * IDs, so per-language modes have a starting point (review finding C4).
     *
     * @param array<string, string|null> $legacy
     */
    private function seedAutomationMappings(array $legacy, string $scope, int $scopeId): void
    {
        // Website-scope rows map 1:1; the default scope seeds website 0
        // (the Router's config fallback covers unseeded websites anyway).
        $websiteId = $scope === 'websites' ? $scopeId : 0;

        $seeds = [
            Trigger::WELCOME => (int)($legacy['subscribe/workflowId'] ?? 0),
            Trigger::ABANDONED_CART => (int)($legacy['abandoned/autoresponderId'] ?? 0),
        ];

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(MappingResource::TABLE_NAME);
        foreach ($seeds as $trigger => $workflowId) {
            if ($workflowId > 0) {
                $connection->insertOnDuplicate(
                    $table,
                    [
                        'website_id' => $websiteId,
                        'trigger_type' => $trigger,
                        'language' => 'default',
                        'account_key' => 'default',
                        'workflow_id' => $workflowId,
                        'is_default_fallback' => 1,
                    ],
                    ['workflow_id', 'is_default_fallback']
                );
            }
        }
    }
}
