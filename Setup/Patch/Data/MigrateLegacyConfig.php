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
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Migration\LegacyConfigMapper;
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
 */
class MigrateLegacyConfig implements DataPatchInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly LegacyConfigMapper $mapper,
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly NotifierInterface $notifier
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
        foreach ($byScope as $scopeKey => $legacy) {
            [$scope, $scopeId] = explode(':', $scopeKey, 2);
            $scope = $scope === 'default' ? ScopeConfigInterface::SCOPE_TYPE_DEFAULT : $scope;
            $result = $this->mapper->map($legacy);

            foreach ($result['configs'] as $config) {
                $value = in_array(LegacyConfigMapper::FLAG_ENCRYPT, $config['flags'], true)
                    ? $this->encryptor->encrypt($config['value'])
                    : $config['value'];
                $this->configWriter->save($config['path'], $value, $scope, (int)$scopeId);
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
            $this->notifier->addNotice('Smaily Connect upgrade', $notice);
        }

        return $this;
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
