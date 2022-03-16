<?php

namespace Smaily\SmailyForMagento\Setup;

use \Magento\Framework\Setup\ModuleDataSetupInterface;
use \Magento\Framework\Setup\ModuleContextInterface;
use Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState\Collection as SubscribersSyncStateCollection;

class UpgradeData implements \Magento\Framework\Setup\UpgradeDataInterface
{
    protected $configWriter;
    protected $scopeConfig;
    protected $storeManager;

    protected $subscribersSyncStateCollection;

    /**
     * Class constructor.
     *
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Framework\App\Config\Storage\WriterInterface $configWriter
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        SubscribersSyncStateCollection $subscribersSyncStateCollection
    ) {
        $this->configWriter = $configWriter;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;

        $this->subscribersSyncStateCollection = $subscribersSyncStateCollection;
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
        if (version_compare($context->getVersion(), '2.0.0', '<')) {
            $this->migration002();
        }
        if (version_compare($context->getVersion(), '3.0.0', '<')) {
            $this->migration003();
        }
    }

    /**
     * Run version 1.0.1 migrations.
     *
     * @access private
     * @return void
     */
    private function migration001()
    {
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

    /**
     * Run version 2.0.0 migrations.
     *
     * @access private
     * @return void
     */
    private function migration002()
    {
        // Replace firstname with first_name, and lastname with last_name.
        //
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
     * Run version 3.0.0 migrations.
     *
     * @access private
     * @return void
     */
    private function migration003()
    {
        $websites = $this->storeManager->getWebsites();

        $connection = $this->subscribersSyncStateCollection->getConnection();
        $tableName = $this->subscribersSyncStateCollection->getMainTable();

        // Get stored last update date.
        $select = $connection
            ->select()
            ->from($tableName, ['last_update_at'])
            ->limit(1);

        $lastSyncedAt = $connection->fetchRow($select);
        $lastSyncedAt = !empty($lastSyncedAt) ? new \DateTimeImmutable($lastSyncedAt['last_update_at']) : null;

        // Truncate subscribers synchronization state collection.
        $connection->truncateTable($tableName);

        foreach ($websites as $website) {
            $this->subscribersSyncStateCollection->updateLastSyncedAt($website->getId(), $lastSyncedAt);
        }
    }
}
