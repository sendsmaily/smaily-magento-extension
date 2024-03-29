<?php

namespace Smaily\SmailyForMagento\Helper;

class Config extends \Magento\Framework\App\Helper\AbstractHelper
{
    const SUBSCRIBERS_SYNC_CRON_PATH = 'crontab/default/jobs/smaily_subscriber_sync/schedule/cron_expr';

    const SETTINGS_NAMESPACE = 'smaily';

    const GROUP_ABANDONED_CART = 'abandoned';
    const GROUP_GENERAL = 'general';
    const GROUP_OPTIN = 'subscribe';
    const GROUP_SUBSCRIBERS_SYNC = 'sync';

    const GENERAL_ENABLED = 'enable';
    const GENERAL_PASSWORD = 'password';
    const GENERAL_SUBDOMAIN = 'subdomain';
    const GENERAL_USERNAME = 'username';

    const OPTIN_ENABLED = 'enableNewsletterSubscriptions';
    const OPTIN_WORKFLOW_ID = 'workflowId';
    const OPTIN_CAPTCHA_ENABLED = 'enableCaptcha';
    const OPTIN_CAPTCHA_TYPE = 'captchaType';
    const OPTIN_CAPTCHA_SITEKEY = 'captchaApiKey';
    const OPTIN_CAPTCHA_SECRETKEY = 'captchaApiSecret';

    const SUBSCRIBERS_SYNC_ENABLED = 'enableCronSync';
    const SUBSCRIBERS_SYNC_FIELDS = 'fields';
    const SUBSCRIBERS_SYNC_FREQUENCY = 'frequency';
    const SUBSCRIBERS_SYNC_LAST_DT = 'lastSyncedAt';

    const ABANDONED_CART_ENABLED = 'enableAbandonedCart';
    const ABANDONED_CART_FIELDS = 'productfields';
    const ABANDONED_CART_INTERVAL = 'syncTime';
    const ABANDONED_CART_WORKFLOW_ID = 'autoresponderId';

    protected $configInterface;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\Config\ConfigResource\ConfigInterface $configInterface
    ) {
        $this->configInterface = $configInterface;
        parent::__construct($context);
    }

    /**
     * Is module enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue(self::GENERAL_ENABLED, self::GROUP_GENERAL, $websiteId);
    }

    /**
     * Is Newsletter Subscriber opt-in triggering enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isSubscriberOptInEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue(self::OPTIN_ENABLED, self::GROUP_OPTIN, $websiteId);
    }

    /**
     * Get Newsletter Subscriber opt-in automation workflow ID.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return int
     */
    public function getSubscriberOptInWorkflowId($websiteId = null)
    {
        return (int) $this->getConfigValue(self::OPTIN_WORKFLOW_ID, self::GROUP_OPTIN, $websiteId);
    }

    /**
     * Is Newsletter Subscriber opt-in CAPTCHA enabled?
     *
     * @param mixed|null $websiteId
     * @access public
     * @return boolean
     */
    public function isSubscriberOptInCaptchaEnabled($websiteId = null)
    {
        return (bool)(int) $this->getConfigValue(self::OPTIN_CAPTCHA_ENABLED, self::GROUP_OPTIN, $websiteId);
    }

    /**
     * Get Newsletter Subscriber opt-in CAPTCHA type.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return string
     */
    public function getSubscriberOptInCaptchaType($websiteId = null)
    {
        return $this->getConfigValue(self::OPTIN_CAPTCHA_TYPE, self::GROUP_OPTIN, $websiteId);
    }

    /**
     * Return Google reCAPTCHA site key.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return string
     */
    public function getSubscriberOptInCaptchaSiteKey($websiteId = null)
    {
        return $this->getConfigValue(self::OPTIN_CAPTCHA_SITEKEY, self::GROUP_OPTIN, $websiteId);
    }

    /**
     * Return Google reCAPTCHA secret key.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return string
     */
    public function getSubscriberOptInCaptchaSecretKey($websiteId = null)
    {
        return $this->getConfigValue(self::OPTIN_CAPTCHA_SECRETKEY, self::GROUP_OPTIN, $websiteId);
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
        return (bool)(int) $this->getConfigValue(
            self::SUBSCRIBERS_SYNC_ENABLED,
            self::GROUP_SUBSCRIBERS_SYNC,
            $websiteId
        );
    }

    /**
     * Return list of subscriber's fields to synchronize to Smaily.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getSubscribersSyncFields($websiteId = null)
    {
        $fields = $this->getConfigValue(self::SUBSCRIBERS_SYNC_FIELDS, self::GROUP_SUBSCRIBERS_SYNC, $websiteId);
        return !empty($fields) ? explode(',', $fields) : [];
    }

    /**
     * Return subscriber's synchronization last date and time in website.
     *
     * @param mixed $websiteId
     * @access public
     * @return \DateTimeImmutable|null
     */
    public function getSubscribersSyncLastSyncedAt($websiteId)
    {
        if ($websiteId === null) {
            throw new \Magento\Framework\Exception\InvalidArgumentException('Missing website ID');
        }

        $dt = $this->getConfigValue(self::SUBSCRIBERS_SYNC_LAST_DT, self::GROUP_SUBSCRIBERS_SYNC, $websiteId);
        return empty($dt) ? null : new \DateTimeImmutable($dt, new \DateTimeZone('UTC'));
    }

    /**
     * Set subscribers' synchronization date and time in website.
     *
     * @param mixed $websiteId
     * @param \DateTimeImmutable $dt
     * @access public
     * @return self
     */
    public function setSubscribersSyncLastSyncedAt($websiteId, \DateTimeImmutable $dt)
    {
        if ($websiteId === null) {
            throw new \Magento\Framework\Exception\InvalidArgumentException('Missing website ID');
        }

        $this->setConfigValue(
            self::SUBSCRIBERS_SYNC_LAST_DT,
            $dt->format('Y-m-d H:i:s'),
            self::GROUP_SUBSCRIBERS_SYNC,
            $websiteId
        );

        return $this;
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
        return (bool)(int) $this->getConfigValue(self::ABANDONED_CART_ENABLED, self::GROUP_ABANDONED_CART, $websiteId);
    }

    /**
     * Get interval in which cart should be abandoned in.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return \DateInterval
     */
    public function getAbadonedCartAbandonInterval($websiteId = null)
    {
        $interval = $this->getConfigValue(self::ABANDONED_CART_INTERVAL, self::GROUP_ABANDONED_CART, $websiteId);
        return \DateInterval::createFromDateString(str_replace(':', ' ', $interval));
    }

    /**
     * Get list of fields that should be passed along with abandoned cart.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return array
     */
    public function getAbandonedCartFields($websiteId = null)
    {
        $fields = $this->getConfigValue(self::ABANDONED_CART_FIELDS, self::GROUP_ABANDONED_CART, $websiteId);
        return !empty($fields) ? explode(',', $fields) : [];
    }

    /**
     * Get abandoned cart automation workflow ID.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return int
     */
    public function getAbandonedCartAutomationId($websiteId = null)
    {
        return (int) $this->getConfigValue(self::ABANDONED_CART_WORKFLOW_ID, self::GROUP_ABANDONED_CART, $websiteId);
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
            'subdomain' => $this->getConfigValue(self::GENERAL_SUBDOMAIN, self::GROUP_GENERAL, $websiteId),
            'username' => $this->getConfigValue(self::GENERAL_USERNAME, self::GROUP_GENERAL, $websiteId),
            'password' => $this->getConfigValue(self::GENERAL_PASSWORD, self::GROUP_GENERAL, $websiteId),
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
        $scope = \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES;
        $path = self::SETTINGS_NAMESPACE . '/' . trim($group, '/') . '/' . trim($setting, '/');
        return $this->scopeConfig->getValue($path, $scope, $websiteId);
    }

    /**
     * Set Magento main configuration value by field.
     *
     * @param string $setting
     * @param string $value
     * @param string $group
     * @param string|null $websiteId
     * @access private
     * @return void
     */
    private function setConfigValue($setting, $value, $group, $websiteId = null)
    {
        $scope = \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES;
        $path = self::SETTINGS_NAMESPACE . '/' . trim($group, '/') . '/' . trim($setting, '/');
        $this->configInterface->saveConfig($path, $value, $scope, $websiteId);
    }
}
