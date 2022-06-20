<?php

declare(strict_types=1);

namespace Smaily\SmailyForMagento\Setup\Patch\Data;

class NormalizeConfigNaming implements \Magento\Framework\Setup\Patch\DataPatchInterface
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
        $captchaType = $this->scopeConfig->getValue('smaily/subscribe/captcha_type');
        if (!empty($captchaType)) {
            $this->configWriter->save('smaily/subscribe/captchaType', $captchaType);
            $this->configWriter->save('smaily/subscribe/captcha_type', '');
        }

        $captchaApiKey = $this->scopeConfig->getValue('smaily/subscribe/captcha_api_key');
        if (!empty($captchaApiKey)) {
            $this->configWriter->save('smaily/subscribe/captchaApiKey', $captchaApiKey);
            $this->configWriter->save('smaily/subscribe/captcha_api_key', '');
        }

        $captchaApiSecret = $this->scopeConfig->getValue('smaily/subscribe/captcha_api_secret');
        if (!empty($captchaApiSecret)) {
            $this->configWriter->save('smaily/subscribe/captchaApiSecret', $captchaApiSecret);
            $this->configWriter->save('smaily/subscribe/captcha_api_secret', '');
        }

        // Rename Abandoned Cart configuration option paths.
        $abandonedCartAutoresponderId = $this->scopeConfig->getValue('smaily/abandoned/ac_ar_id');
        if (!empty($abandonedCartAutoresponderId)) {
            $this->configWriter->save('smaily/abandoned/autoresponderId', $abandonedCartAutoresponderId);
            $this->configWriter->save('smaily/abandoned/ac_ar_id', '');
        }

        $abandonedCartSyncTime = $this->scopeConfig->getValue('smaily/abandoned/sync_time');
        if (!empty($abandonedCartSyncTime)) {
            $this->configWriter->save('smaily/abandoned/syncTime', $abandonedCartSyncTime);
            $this->configWriter->save('smaily/abandoned/sync_time', '');
        }
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return '1.0.1';
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
