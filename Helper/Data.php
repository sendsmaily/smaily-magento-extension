<?php

namespace Smaily\SmailyForMagento\Helper;

use Magento\Store\Model\ScopeInterface;

use Smaily\SmailyForMagento\Helper\Config;
use Smaily\SmailyForMagento\Model\API\ClientFactory as SmailyAPIClientFactory;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $curl;
    protected $logger;

    protected $config;
    protected $smailyApiClientFactory;

    /**
     * Settings section value.
     */
    const XML_PATH = 'smaily/';

    /**
     * Settings page subscribe group id-s.
     */
    protected $subscribeSettings = [
        'enableNewsletterSubscriptions',
        'enableCaptcha',
        'captchaType',
        'captchaApiKey',
        'captchaApiSecret'
    ];

    /**
     * Settings page sync group id-s.
     */
    protected $syncSettings = ['fields', 'frequency', 'enableCronSync'];

    /**
     * Settings page abandoned group id-s.
     */
    protected $abandonedSettings = ['autoresponderId', 'syncTime', 'productfields', 'enableAbandonedCart'];

    private $connection;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\HTTP\Client\Curl $curl,
        Config $config,
        SmailyAPIClientFactory $smailyApiClientFactory
    ) {
        $this->curl = $curl;
        $this->logger = $context->getLogger();

        $this->config = $config;
        $this->smailyApiClientFactory = $smailyApiClientFactory;

        parent::__construct($context);
    }

    /**
     * Check if Smaily Extension is enabled.
     *
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) $this->getGeneralConfig('enable');
    }

    /**
     * Check if newsletter subscribtion form opt-in sync is enabled.
     *
     * @return boolean
     */
    public function isNewsletterSubscriptionEnabled()
    {
        return (bool) $this->getGeneralConfig('enableNewsletterSubscriptions');
    }

    /**
     * Check if CAPTCHA is enabled for newsletter form.
     *
     * @return boolean
     */
    public function isCaptchaEnabled()
    {
        return (bool) $this->getGeneralConfig('enableCaptcha');
    }

    /**
     * Checks if CPATCHA should be checked.
     *
     * @return void
     */
    public function shouldCheckCaptcha()
    {
        $check = false;
        // Check CAPTCHA only if module, subscriber collection and CAPTCHA is enabled.
        if ($this->isEnabled() && $this->isNewsletterSubscriptionEnabled() && $this->isCaptchaEnabled()) {
            $check = true;
        }

        return $check;
    }

    /**
     * Get Magento main configuration by field
     *
     * @return string
     */
    public function getConfigValue($configPath, $storeId = null)
    {
        return $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Get Smaily configuration by field
     *
     * @return string
     */
    public function getGeneralConfig($code, $storeId = null)
    {
        $tab = 'general';
        if (in_array($code, $this->subscribeSettings, true)) {
            $tab = 'subscribe';
        }
        if (in_array($code, $this->syncSettings, true)) {
            $tab = 'sync';
        }
        if (in_array($code, $this->abandonedSettings, true)) {
            $tab = 'abandoned';
        }

        return trim($this->getConfigValue(self::XML_PATH . $tab . '/' . $code, $storeId));
    }

    /**
     * Get CAPTCHA type to use in newsletter signup form.
     *
     * @return string Captcha type.
     */
    public function getCaptchaType()
    {
        return $this->getGeneralConfig('captchaType');
    }

    /**
     * Get reCAPTCHA public API key.
     *
     * @return string public key.
     */
    public function getCaptchaApiKey()
    {
        return $this->getGeneralConfig('captchaApiKey');
    }

    /**
     * Get reCAPTCHA private API key.
     *
     * @return string private key.
     */
    public function getCaptchaApiSecretKey()
    {
        return $this->getGeneralConfig('captchaApiSecret');
    }

    /**
     * Get Smaily Subdomain
     *
     * @return string
     */
    public function getSubdomain()
    {
        $domain = $this->getGeneralConfig('subdomain');

        $domain = trim(strtolower(str_replace(['https://', 'http://', '/', '.sendsmaily.net'], '', $domain)));
        return $domain;
    }

    /**
     * Get Customer Group name by Group Id
     *
     * @return string
     */
    public function getCustomerGroupName($group_id)
    {
        $group_id = (int) $group_id;
        $list = [];
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $customerGroups = $objectManager->get('\Magento\Customer\Model\ResourceModel\Group\Collection');

        foreach ($customerGroups->toOptionArray() as $opt) {
            $list[(int) $opt['value']] = trim($opt['label']);
        }

        return isset($list[$group_id]) ? $list[$group_id] : 'Customer';
    }

    /**
     * Get AutoResponders list from Smaily API
     *
     * @return array
     */
    public function getAutoresponders()
    {
        $autoresponders = $this->callApi('workflows', ['trigger_type' => 'form_submitted']);
        $list = [];

        if (!empty($autoresponders)) {
            foreach ($autoresponders as $autoresponder) {
                    $list[$autoresponder['id']] = trim($autoresponder['title']);
            }
        }

        return $list;
    }

    /**
     * Subscribe/Import Customer to Smaily by email
     *
     * @return array
     *  Smaily api response
     */
    public function subscribe($email, $data = [], $update = 0)
    {
        $address = [
            'email' => $email,
            'is_unsubscribed' => $update
        ];

        if (!empty($data)) {
            $fields = explode(',', $this->getGeneralConfig('fields'));

            foreach ($data as $field => $val) {
                if ($field === 'name' || in_array($field, $fields, true)) {
                    $address[$field] = trim($val);
                }
            }
        }

        return $this->callApi('contact', $address, 'POST');
    }

    /**
     * Initialize and return an instance of Smaily API client.
     *
     * @param mixed|null $websiteId
     * @access public
     * @return Smaily\SmailyForMagento\Model\API\Client
     */
    public function getSmailyApiClient($websiteId = null) {
        $credentials = $this->config->getSmailyApiCredentials($websiteId);
        return $this->smailyApiClientFactory->create()
            ->setBaseUrl("https://${credentials['subdomain']}.sendsmaily.net")
            ->setCredentials($credentials['username'], $credentials['password']);
    }

    /**
     * Get Subscribe/Import Customer to Smaily by email with OPT-IN trigger.
     *
     * @return array
     *  Smaily api response
     */
    public function optInSubscriber($email, $data = [])
    {
        $address = [
            'email' => $email,
        ];

        if (!empty($data)) {
            foreach ($data as $field => $val) {
                    $address[$field] = trim($val);
            }
        }

        $post = [
            'addresses' => [$address],
        ];

        return $this->callApi('autoresponder', $post, 'POST');
    }

    /**
     * Remove trailing zeroes from string.
     * For example 1.1030 -> 1.103 and 1.000 -> 1
     *
     * @param string $value
     * @return string
     */
    public function stripTrailingZeroes($value)
    {
        $trimmed = rtrim($value, '0');

        // Remove the trailing "." if quantity 1.
        if (substr($trimmed, -1) === '.') {
            $trimmed = substr($trimmed, 0, -1);
        }

        return $trimmed;
    }

    /**
     * Validates Smaily API Credentials.
     *
     * @param string $subdomain     Smaily subdomain
     * @param string $username      Smaily Api username
     * @param string $password      Smaily Api password
     * @return boolean $response    True if Ok, False if not authenticated, null if error.
     */
    public function validateApiCredentrials($subdomain, $username, $password)
    {
        $response = false;
        $apiUrl = 'https://' . $subdomain . '.sendsmaily.net/api/autoresponder.php';

        try {
            $this->curl->setCredentials($username, $password);
            $this->curl->get($apiUrl);
            $responseStatus = $this->curl->getStatus();
            if ($responseStatus === 200) {
                $response = true;
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }
        return $response;
    }

    /**
     * Validate reCAPTCHA response value.
     *
     * @param string $captchaResponse Response from google CAPTCHA
     * @param string $secret reCAPTCHA api secret key.
     * @return boolean
     */
    public function isCaptchaValid($captchaResponse, $secret)
    {
        $validated = false;

        $url = 'https://www.google.com/recaptcha/api/siteverify';
        $data = [
            'secret' => $secret,
            'response' => $captchaResponse,
        ];

        try {
            $this->curl->post($url, $data);
            $response = json_decode($this->curl->getBody(), true);
            if ($response['success']) {
                $validated = true;
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            return false;
        }

        return $validated;
    }

    /**
     * Call to Smaily API
     *
     * @return array
     */
    public function callApi($endpoint, $data = [], $method = 'GET')
    {
        $response = [];
        // get smaily subdomain, username and password
        $subdomain = $this->getSubdomain();
        $username = $this->getGeneralConfig('username');
        $password = $this->getGeneralConfig('password');
        // create api url
        $apiUrl = 'https://' . $subdomain . '.sendsmaily.net/api/' . trim($endpoint, '/') . '.php';
        try {
            if ($method === 'GET') {
                $data = urldecode(http_build_query($data));
                $apiUrl = $apiUrl . '?' . $data;
                $this->curl->setCredentials($username, $password);
                $this->curl->get($apiUrl);

                $response = (array) json_decode($this->curl->getBody(), true);
            } elseif ($method === 'POST') {
                $this->curl->setCredentials($username, $password);
                $this->curl->post($apiUrl, $data);

                $response = (array) json_decode($this->curl->getBody(), true);

                // Validate response.
                if (!array_key_exists('code', $response)) {
                    throw new \Exception('Something went wrong with the request.');
                }
                if ((int) $response['code'] !== 101) {
                    throw new \Exception($response['message']);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $response = [];
        }

        return $response;
    }
}
