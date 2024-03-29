<?php

namespace Smaily\SmailyForMagento\Plugin;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Model\HTTP\ClientFactory as HTTPClientFactory;

class SaveConfig
{
    protected $configValueFactory;
    protected $logger;
    protected $scopeConfig;

    protected $config;
    protected $httpClientFactory;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\Config\ValueFactory $configValueFactory,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        HTTPClientFactory $httpClientFactory
    ) {
        $this->configValueFactory = $configValueFactory;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;

        $this->config = $config;
        $this->httpClientFactory = $httpClientFactory;
    }

    /**
     * Run additional validation logic before saving configuration.
     *
     * @param \Magento\Config\Model\Config $config
     * @access public
     * @return void
     */
    public function beforeSave(\Magento\Config\Model\Config $config)
    {
        if ($config->getSection() !== Config::SETTINGS_NAMESPACE) {
            return;
        }

        $subdomain = $this->resolveConfigValue($config, Config::GENERAL_SUBDOMAIN, Config::GROUP_GENERAL);
        $username = $this->resolveConfigValue($config, Config::GENERAL_USERNAME, Config::GROUP_GENERAL);
        $password = $this->resolveConfigValue($config, Config::GENERAL_PASSWORD, Config::GROUP_GENERAL);

        $client = $this->httpClientFactory->create()
            ->setBaseUrl("https://{$subdomain}.sendsmaily.net")
            ->setCredentials($username, $password);

        try {
            $client->get('/api/workflows.php');
        } catch (\Exception $e) {
            $this->logger->error("Unable to validate Smaily API credentials: " . $e->getMessage());

            if ($e->getCode() === 401 || $e->getCode() === 403 || $e->getCode() === 404) {
                throw new \Magento\Framework\Exception\ValidatorException(__('Check API credentials, unauthorized.'));
            } else {
                throw new \Magento\Framework\Exception\ValidatorException(
                    __('Could not validate API credentials. Please try again later.')
                );
            }
        }
    }

    /**
     * Run additional updates after configuration has been saved.
     *
     * @param \Magento\Config\Model\Config $config
     * @access public
     * @return void
     */
    public function afterSave(\Magento\Config\Model\Config $config)
    {
        if ($config->getSection() !== Config::SETTINGS_NAMESPACE) {
            return;
        }

        // Update Newsletter Subscribers synchronization CRON job frequency.
        $frequency = $this->resolveConfigValue(
            $config,
            Config::SUBSCRIBERS_SYNC_FREQUENCY,
            Config::GROUP_SUBSCRIBERS_SYNC
        );

        $this->configValueFactory->create()
            ->load(Config::SUBSCRIBERS_SYNC_CRON_PATH, 'path')
            ->setValue($frequency)
            ->setPath(Config::SUBSCRIBERS_SYNC_CRON_PATH)
            ->save();
    }

    /**
     * Return inherited configuration value.
     *
     * @param \Magento\Config\Model\Config $config
     * @param string $setting
     * @access private
     * @return mixed
     */
    private function resolveConfigValue(\Magento\Config\Model\Config $config, $setting, $group)
    {
        $value = $config->getDataByPath('groups/' . $group . '/fields/' . $setting);
        if (isset($value['inherit'])) {
            // Note! When switching to store view based configuration,
            // the scope (incl. website) needs to be adjusted as well.
            return $this->scopeConfig->getValue(
                Config::SETTINGS_NAMESPACE . '/' . $group . '/' . $setting,
                \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );
        }

        return $value['value'];
    }
}
