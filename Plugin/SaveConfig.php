<?php

namespace Smaily\SmailyForMagento\Plugin;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Model\API\ClientFactory as SmailyAPIClientFactory;

class SaveConfig
{
    protected $logger;
    protected $scopeConfig;

    protected $config;
    protected $smailyApiClientFactory;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Psr\Log\LoggerInterface $logger,
        Config $config,
        SmailyAPIClientFactory $smailyApiClientFactory
    )
    {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;

        $this->config = $config;
        $this->smailyApiClientFactory = $smailyApiClientFactory;
    }

    /**
     * Override configuration save to validate Smaily API credentials.
     *
     * @access public
     * @return void
     */
    public function beforeSave(\Magento\Config\Model\Config $config)
    {
        if ($config->getSection() !== Config::SETTINGS_NAMESPACE) {
            return;
        }

        $subdomain = $this->resolveConfigValue($config, Config::SETTINGS_GENERAL_SUBDOMAIN);
        $username = $this->resolveConfigValue($config, Config::SETTINGS_GENERAL_USERNAME);
        $password = $this->resolveConfigValue($config, Config::SETTINGS_GENERAL_PASSWORD);

        $client = $this->smailyApiClientFactory->create()
            ->setBaseUrl("https://${subdomain}.sendsmaily.net")
            ->setCredentials($username, $password);

        try {
            $client->get('/api/workflows.php');
        }
        catch (\Exception $e) {
            $this->logger->error("Unable to validate Smaily API credentials: " . $e->getMessage());

            if ($e->getCode() === 401 || $e->getCode() === 403 || $e->getCode() === 404) {
                throw new \Magento\Framework\Exception\ValidatorException(__('Check API credentials, unauthorized.'));
            }
            else {
                throw new \Exception(__('Could not validate API credentials. Please try again later.'));
            }
        }
    }

    /**
     * Return inherited configuration value.
     *
     * @param \Magento\Config\Model\Config $config
     * @param string $setting
     * @access private
     * @return mixed
     */
    private function resolveConfigValue(\Magento\Config\Model\Config $config, $setting)
    {
        $value = $config->getDataByPath('groups/' . Config::SETTINGS_GROUP_GENERAL . '/fields/' . $setting);
        if (isset($value['inherit'])) {
            // Note! When switching to store view based configuration,
            // the scope (incl. website) needs to be adjusted as well.
            return $this->scopeConfig->getValue(
                Config::SETTINGS_NAMESPACE . '/' . Config::SETTINGS_GROUP_GENERAL . '/' . $setting,
                \Magento\Framework\App\Config\ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            );
        }

        return $value['value'];
    }
}
