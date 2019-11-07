<?php

namespace Smaily\SmailyForMagento\Setup;

use Magento\Framework\Setup\UpgradeDataInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

class UpgradeData implements UpgradeDataInterface
{
    protected $scopeConfig;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
    }

    public function upgrade(ModuleDataSetupInterface $setup, ModuleContextInterface $context)
    {

        if (version_compare($context->getVersion(), '1.0.1', '<')) {
            // CAPTCHA.
            $captcha_type = $this->scopeConfig->getValue('smaily/subscribe/captcha_type');
            if (!empty($captcha_type)) {
                $this->configWriter->save("smaily/subscribe/captchaType", $captcha_type);
                $this->configWriter->save("smaily/subscribe/captcha_type", '');
            }
            $captcha_api_key = $this->scopeConfig->getValue('smaily/subscribe/captcha_api_key');
            if (!empty($captcha_api_key)) {
                $this->configWriter->save("smaily/subscribe/captchaApiKey", $captcha_api_key);
                $this->configWriter->save("smaily/subscribe/captcha_api_key", '');
            }
            $captcha_api_secret = $this->scopeConfig->getValue('smaily/subscribe/captcha_api_secret');
            if (!empty($captcha_api_secret)) {
                $this->configWriter->save("smaily/subscribe/captchaApiSecret", $captcha_api_secret);
                $this->configWriter->save("smaily/subscribe/captcha_api_secret", '');
            }
            // ABANDONED CART.
            $ac_ar_id = $this->scopeConfig->getValue('smaily/abandoned/ac_ar_id');
            if (!empty($ac_ar_id)) {
                $this->configWriter->save("smaily/abandoned/autoresponderId", $ac_ar_id);
                $this->configWriter->save("smaily/abandoned/ac_ar_id", '');
            }
            $sync_time = $this->scopeConfig->getValue('smaily/abandoned/sync_time');
            if (!empty($sync_time)) {
                $this->configWriter->save("smaily/abandoned/syncTime", $ac_ar_id);
                $this->configWriter->save("smaily/abandoned/sync_time", '');
            }
        }
    }
}
