<?php

namespace Smaily\SmailyForMagento\Helper;

use \Magento\Store\Model\ScopeInterface;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const SETTINGS_NAMESPACE = 'smaily';

    const SETTINGS_GROUP_GENERAL = 'general';
    const SETTINGS_GROUP_SUBSCRIBERS_SYNC = 'sync';
    const SETTINGS_GROUP_ABANDONED_CART = 'abandoned';

    /**
     * Is module enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue('enable', self::SETTINGS_GROUP_GENERAL, $websiteId);
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
        return (bool)(int) $this->getConfigValue('enableCronSync', self::SETTINGS_GROUP_SUBSCRIBERS_SYNC, $websiteId);
    }

    /**
     * Return list of subscriber's fields to synchronize to Smaily.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getSubscribersSyncFields($websiteId = null) {
        $fields = $this->getConfigValue('fields', self::SETTINGS_GROUP_SUBSCRIBERS_SYNC, $websiteId);
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
        return (bool)(int) $this->getConfigValue('enableAbandonedCart', self::SETTINGS_GROUP_ABANDONED_CART, $websiteId);
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
            'subdomain' => $this->getConfigValue('subdomain', self::SETTINGS_GROUP_GENERAL, $websiteId),
            'username' => $this->getConfigValue('username', self::SETTINGS_GROUP_GENERAL, $websiteId),
            'password' => $this->getConfigValue('password', self::SETTINGS_GROUP_GENERAL, $websiteId),
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
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }
}
