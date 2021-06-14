<?php

namespace Smaily\SmailyForMagento\Setup;

use \Magento\Framework\Setup\ModuleDataSetupInterface;
use \Magento\Framework\Setup\ModuleContextInterface;

class UpgradeData implements \Magento\Framework\Setup\UpgradeDataInterface
{
    protected $configWriter;
    protected $scopeConfig;

    /**
     * Class constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * Run data upgrade.
     *
     * @param \Magento\Framework\Setup\ModuleDataSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface $context
     * @access public
     * @return void
     */
    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {
        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            $this->migration001();
        }
    }

    /**
     * Run migrations to version 1.0.1.
     *
     * @access private
     * @return void
     */
    private function migration001() {
        // Rename CAPTCHA configuration option paths.
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
}
