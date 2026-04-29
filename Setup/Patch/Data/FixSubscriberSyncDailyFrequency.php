<?php

namespace Smaily\SmailyForMagento\Setup\Patch\Data;

use \Magento\Framework\Setup\ModuleDataSetupInterface;
use \Magento\Framework\Setup\Patch\DataPatchInterface;

class FixSubscriberSyncDailyFrequency implements DataPatchInterface
{
    private const OLD_EXPR = '0 * */1 * *';
    private const NEW_EXPR = '0 0 * * *';

    private const PATHS = [
        'smaily/sync/frequency',
        'crontab/default/jobs/smaily_subscriber_sync/schedule/cron_expr',
    ];

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): void
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        foreach (self::PATHS as $path) {
            $connection->update(
                $table,
                ['value' => self::NEW_EXPR],
                ['path = ?' => $path, 'value = ?' => self::OLD_EXPR]
            );
        }
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}