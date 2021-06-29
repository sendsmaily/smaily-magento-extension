<?php

namespace Smaily\SmailyForMagento\Helper;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const SUBSCRIBERS_SYNC_CRON_PATH = 'crontab/default/jobs/smaily_subscriber_sync/schedule/cron_expr';

    const SETTINGS_NAMESPACE = 'smaily';

    const SETTINGS_GROUP_ABANDONED_CART = 'abandoned';
    const SETTINGS_GROUP_GENERAL = 'general';
    const SETTINGS_GROUP_SUBSCRIBERS_SYNC = 'sync';

    const SETTINGS_GENERAL_ENABLED = 'enable';
    const SETTINGS_GENERAL_PASSWORD = 'password';
    const SETTINGS_GENERAL_SUBDOMAIN = 'subdomain';
    const SETTINGS_GENERAL_USERNAME = 'username';

    const SETTINGS_SUBSCRIBERS_SYNC_ENABLED = 'enableCronSync';
    const SETTINGS_SUBSCRIBERS_SYNC_FIELDS = 'fields';
    const SETTINGS_SUBSCRIBERS_SYNC_FREQUENCY = 'frequency';

    const SETTINGS_ABANDONED_CART_ENABLED = 'enableAbandonedCart';
    const SETTINGS_ABANDONED_CART_FIELDS = 'productfields';
    const SETTINGS_ABANDONED_CART_INTERVAL = 'syncTime';
    const SETTINGS_ABANDONED_CART_WORKFLOW_ID = 'autoresponderId';

    /**
     * Is module enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue(self::SETTINGS_GENERAL_ENABLED, self::SETTINGS_GROUP_GENERAL, $websiteId);
    }

    /**
     * Is Newsletter Subscribers synchronization CRON job enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isSubscribersSyncEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue(self::SETTINGS_SUBSCRIBERS_SYNC_ENABLED, self::SETTINGS_GROUP_SUBSCRIBERS_SYNC, $websiteId);
    }

    /**
     * Return list of subscriber's fields to synchronize to Smaily.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getSubscribersSyncFields($websiteId = null) {
        $fields = $this->getConfigValue(self::SETTINGS_SUBSCRIBERS_SYNC_FIELDS, self::SETTINGS_GROUP_SUBSCRIBERS_SYNC, $websiteId);
        return !empty($fields) ? explode(',', $fields) : array();
    }

    /**
     * Is Abandoned Cart CRON job enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isAbandonedCartCronEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue(self::SETTINGS_ABANDONED_CART_ENABLED, self::SETTINGS_GROUP_ABANDONED_CART, $websiteId);
    }

    /**
     * Get interval in which cart should be abandoned in.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return \DateInterval
     */
    public function getAbadonedCartAbandonInterval($websiteId = null) {
        $interval = $this->getConfigValue(self::SETTINGS_ABANDONED_CART_INTERVAL, self::SETTINGS_GROUP_ABANDONED_CART, $websiteId);
        return \DateInterval::createFromDateString(str_replace(':', ' ', $interval));
    }

    /**
     * Get list of fields that should be passed along with abandoned cart.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getAbandonedCartFields($websiteId = null) {
        $fields = $this->getConfigValue(self::SETTINGS_ABANDONED_CART_FIELDS, self::SETTINGS_GROUP_ABANDONED_CART, $websiteId);
        return !empty($fields) ? explode(',', $fields) : array();
    }

    /**
     * Get abandoned cart automation workflow ID.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return int
     */
    public function getAbandonedCartAutomationId($websiteId = null) {
        return (int) $this->getConfigValue(self::SETTINGS_ABANDONED_CART_WORKFLOW_ID, self::SETTINGS_GROUP_ABANDONED_CART, $websiteId);
    }

    /**
     * Returns Smaily API credentials.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getSmailyApiCredentials($websiteId = null)
    {
        return [
            'subdomain' => $this->getConfigValue(self::SETTINGS_GENERAL_SUBDOMAIN, self::SETTINGS_GROUP_GENERAL, $websiteId),
            'username' => $this->getConfigValue(self::SETTINGS_GENERAL_USERNAME, self::SETTINGS_GROUP_GENERAL, $websiteId),
            'password' => $this->getConfigValue(self::SETTINGS_GENERAL_PASSWORD, self::SETTINGS_GROUP_GENERAL, $websiteId),
        ];
    }

    /**
     * Get Magento main configuration by field
     *
     * @param string $setting
     * @param string $group
     * @param string|null $websiteId
     * @access private
     * @return string
     */
    private function getConfigValue($setting, $group, $websiteId = null)
    {
        $path = self::SETTINGS_NAMESPACE . '/' . trim($group, '/') . '/' . trim($setting, '/');
        return $this->scopeConfig->getValue($path, \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }
}
