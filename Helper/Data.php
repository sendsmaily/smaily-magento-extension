<?php

namespace Smaily\SmailyForMagento\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use \Magento\Framework\HTTP\Client\Curl;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $logger;
    protected $curl;

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
        Context $context,
        Curl $curl
    ) {
        $this->logger = $context->getLogger();
        $this->curl = $curl;
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
     * Check  Smaily Abandoned Cart is enabled
     *
     * @return bool
     */
    public function isAbandonedCartEnabled()
    {
        return (bool) $this->getGeneralConfig('enableAbandonedCart');
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
     * Updates remainder date of Abandoned Cart
     *
     * @param string $quoteId       Cart id
     * @param string $reminderDate  time when to remind customer
     * @return void
     */
    private function updateReminderDate($quoteId, $reminderDate)
    {
        if (!isset($this->connection)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->create('\Magento\Framework\App\ResourceConnection');
            $this->connection = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        }

        $table = $this->connection->getTableName('quote');
        $sql = "UPDATE $table SET reminder_date = :REMINDER_DATE WHERE entity_id = :QUOTE_ID";
        $binds = [
            'QUOTE_ID' => $quoteId,
            'REMINDER_DATE' => $reminderDate
        ];
        return $this->connection->query($sql, $binds);
    }

    /**
     * Updates Abandoned cart sent mail status in database
     *
     * @param string $quoteId Cart id
     * @return void
     */
    private function updateSentStatus($quoteId)
    {
        if (!isset($this->connection)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->create('\Magento\Framework\App\ResourceConnection');
            $this->connection = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        }
        $table = $this->connection->getTableName('quote');
        $sql = "UPDATE $table SET is_sent = '1' WHERE entity_id = :QUOTE_ID";
        $binds = ['QUOTE_ID' => $quoteId];
        return $this->connection->query($sql, $binds);
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
     * Call to Smaily Autoresponder api;
     *
     * @return void
     */
    public function cronAbandonedcart($orders)
    {
        // Get sync interval and fields from settings.
        $syncTime = str_replace(':', ' ', $this->getGeneralConfig('syncTime'));
        $fields = explode(',', $this->getGeneralConfig('productfields'));
        $currentDate = strtotime(date('Y-m-d H:i') . ':00');
        foreach ($orders as $row) {
            $quote_id = $row['quote_id'];
            $newCart = empty($row['reminder_date']);

            if ($newCart) {
                $nextDate = strtotime($syncTime, $currentDate);
                $this->updateReminderDate($quote_id, date('Y-m-d H:i:s', $nextDate));
                continue;
            } else {
                $nextDate = strtotime($row['reminder_date']);
            }

            // Is email sent.
            $isSent = (int) $row['is_sent'] === 1 ? true : false;
            // Send remainder mail if reminder date has passed and mail not sent.
            if ($currentDate >= $nextDate && !$isSent) {
                // Prepare fields for Smaily abandoned cart template.
                $preparedCart = $this->prepareCartData($row, $fields);
                // Send cart data to Smaily autoresponder.
                $response = $this->sendAbandonedCartEmail($preparedCart);
                // If successful log quote id else log error message
                $result = '';
                if (array_key_exists('message', $response) && $response['message'] == 'OK') {
                    // Update quote sent status
                    $this->updateSentStatus($quote_id);
                    // Log message
                    $result = 'Quote id: ' . $quote_id . ' > Sent';
                } else {
                    $result = 'Quote id: ' . $quote_id . ' > Error';
                }
                // create log for api response.
                $writer = new \Zend\Log\Writer\Stream(BP. '/var/log/smly_cart_cron.log');
                $logger = new \Zend\Log\Logger();
                $logger->addWriter($writer);
                $logger->info($result);
            }
        }
    }

    /**
     * Formats cart fields to standard abandoned cart template fields used in Smaily.
     *
     * @param array $row            Cart information.
     * @param array $selectedFields Fields selected for template.
     * @return array                Array of 'customer_data' and 'products_data' sections.
     */
    private function prepareCartData($row, $selectedFields)
    {
        // Populate customer data section.
        $customerData = [
            'email' => $row['customer_email']
        ];

        if (in_array('first_name', $selectedFields, true)) {
            $customerData['first_name'] = $row['customer_firstname'];
        }

        if (in_array('last_name', $selectedFields, true)) {
            $customerData['last_name'] = $row['customer_lastname'];
        }
        // Populate product data section.
        $productsData = [];
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $priceHelper = $objectManager->create('Magento\Framework\Pricing\Helper\Data');

        $fieldsAvailable = $this->getAllAbandonedCartFields();
        foreach ($row['products'] as $product) {
            $_product = [];
            foreach ($fieldsAvailable as $field) {
                if (in_array($field, $selectedFields)) {
                    switch ($field) {
                        case 'first_name':
                        case 'last_name':
                            // Skip customer fields.
                            break;
                        case 'qty':
                            // Transform qty to quantity and strip trailing zeroes.
                            $_product['product_quantity'] = $this->stripTrailingZeroes($product[$field]);
                            break;
                        case 'description':
                            $productObject = $objectManager->
                                create('Magento\Catalog\Model\Product')->
                                load($product['product_id']);
                            $description = $productObject->getDescription();
                            $_product['product_description'] = htmlspecialchars($description);
                            break;
                        case 'price':
                        case 'base_price':
                            // Format price as store displays.
                            $_product['product_' . $field ] = $priceHelper->currency($product[$field], true, false);
                            break;
                        default:
                            $_product['product_' . $field ] = $product[$field];
                            break;
                    }
                }
            }
            $productsData[] = $_product;
        }

        return [
            'customer_data' => $customerData,
            'products_data' => $productsData,
        ];
    }

    /**
     * Get all fields available for abandoned cart template.
     * From Model\Config\Source\ProductFields
     *
     * @return array Array of values for abandoned cart template
     */
    public function getAllAbandonedCartFields()
    {
        $arr = [];

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $model = $objectManager->create('Smaily\SmailyForMagento\Model\Config\Source\ProductFields');
        $fields = $model->toOptionArray();

        foreach ($fields as $field) {
            $arr[] = $field['value'];
        }

        return $arr;
    }

    /**
     * Sends abandoned cart email.
     *
     * @param array $preparedCart   Prepared cart to send to Smaily.
     * @return array                Smaily response.
     */
    public function sendAbandonedCartEmail($preparedCart)
    {
        $customerData = $preparedCart['customer_data'];
        $productsData = $preparedCart['products_data'];

        $autoRespId = $this->getGeneralConfig('autoresponderId');

        // Populate customer data fields.
        $address = [];
        foreach ($customerData as $key => $value) {
            $address[$key] = $value;
        }

        //If more than one product in abandoned cart iterate to products array
        if (count($productsData) > 10) {
            $address['over_10_products'] = 'true';
        } elseif (count($productsData) > 1) {
            $length = count($productsData);
            if ($length > 10) {
                $length = 10;
            }
            for ($i=0; $i < $length; $i++) {
                foreach ($productsData[$i] as $key => $value) {
                    $itemNumber = $i + 1;
                    $address[$key . '_' . $itemNumber] = $value;
                }
            }
        } else {
            foreach ($productsData[0] as $key => $val) {
                $address[$key . '_1'] = $val;
            }
        }
        $query = [
            'autoresponder' => $autoRespId,
            'addresses' => [$address],
        ];

        return $this->callApi('autoresponder', $query, 'POST');
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
