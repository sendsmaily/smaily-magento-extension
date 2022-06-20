<?php

declare(strict_types=1);

namespace Smaily\SmailyForMagento\Setup\Patch\Data;

class NormalizeSyncFields implements \Magento\Framework\Setup\Patch\DataPatchInterface
{
    private $moduleDataSetup;

    private $configWriter;
    private $scopeConfig;

    /**
     * @param \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
    )
    {
        $this->moduleDataSetup = $moduleDataSetup;

        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        // Note! This does not affect website specific settings, and that is OK, because
        // prior to this version, there weren't any website specific settings.
        $fields = $this->scopeConfig->getValue('smaily/sync/fields');
        if (!empty($fields)) {
            $fields = explode(',', $fields);

            // Replace firstname with first_name.
            if (in_array('firstname', $fields)) {
                $fields = array_diff($fields, ['firstname']);
                $fields[] = 'first_name';
            }

            // Replace lastname with last_name.
            if (in_array('lastname', $fields)) {
                $fields = array_diff($fields, ['lastname']);
                $fields[] = 'last_name';
            }

            $this->configWriter->save('smaily/sync/fields', implode(',', $fields));
        }
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return '2.0.0';
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }
}
