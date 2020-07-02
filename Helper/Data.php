<?php

namespace Smaily\SmailyForMagento\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use \Magento\Framework\HTTP\Client\Curl;
use \Magento\Framework\App\Request\Http;
use \Magento\Framework\App\State;
use \Magento\Store\Model\StoreManagerInterface;
use \Magento\Store\Model\ResourceModel\Website\CollectionFactory;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $logger;
    protected $curl;
    protected $request;
    protected $state;
    protected $storeManager;
    protected $websiteCollectionFactory;

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
        Curl $curl,
        Http $request,
        State $state,
        StoreManagerInterface $storeManager,
        CollectionFactory $websiteCollectionFactory
    ) {
        $this->logger = $context->getLogger();
        $this->curl = $curl;
        $this->request = $request;
        $this->state = $state;
        $this->storeManager = $storeManager;
        $this->websiteCollectionFactory = $websiteCollectionFactory;
        parent::__construct($context);
    }

    /**
     * Check if Smaily Extension is enabled.
     *
     * @return bool
     */
    private function isEnabled()
    {
        return (bool) $this->getSmailyConfig('enable');
    }

    /**
     * Check if Smaily Extension is enabled for specific Website.
     *
     * @return bool
     */
    public function isEnabledForWebsite($websiteId)
    {
        return (bool) $this->getSmailyConfig('enable', $websiteId);
    }

    /**
     * Check if newsletter subscribtion form opt-in sync is enabled.
     *
     * @return boolean
     */
    private function isNewsletterSubscriptionEnabled()
    {
        return (bool) $this->getSmailyConfig('enableNewsletterSubscriptions');
    }

    /**
     * Check if newsletter subscribtion form opt-in sync is enabled for specific Website.
     *
     * @return boolean
     */
    public function isNewsletterSubscriptionEnabledForWebsite($websiteId)
    {
        return (bool) $this->getSmailyConfig('enableNewsletterSubscriptions', $websiteId);
    }

    /**
     * Check if CAPTCHA is enabled for newsletter form.
     *
     * @return boolean
     */
    private function isCaptchaEnabled()
    {
        return (bool) $this->getSmailyConfig('enableCaptcha');
    }

    /**
     * Checks if CAPTCHA should be checked.
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
     * Check Smaily Cron Sync is enabled.
     *
     * @return bool
     */
    public function isCronEnabledForWebsite($websiteId)
    {
        return (bool) $this->getSmailyConfig('enableCronSync', $websiteId);
    }

    /**
     * Check Smaily Abandoned Cart is enabled.
     *
     * @return bool
     */
    private function isAbandonedCartEnabledForWebsite($websiteId)
    {
        return (bool) $this->getSmailyConfig('enableAbandonedCart', $websiteId);
    }

    /**
     * Get Magento configuration by field and website Id.
     *
     * @return string
     */
    public function getConfigValue($configPath, $websiteId = null)
    {
        return $this->scopeConfig->getValue($configPath, ScopeInterface::SCOPE_WEBSITE, $websiteId);
    }

    /**
     * Get Website ID by current State area.
     *
     * @return int Website ID
     */
    private function getCurrentWebsiteId()
    {
        // Admin area
        if ($this->state->getAreaCode() === \Magento\Framework\App\Area::AREA_ADMINHTML) {
            $websiteId = (int) $this->request->getParam('website', 0);
            return (int) $websiteId;
        }
        // Public area
        $websiteId = $this->storeManager->getStore()->getWebsiteId();
        return (int) $websiteId;
    }

    /**
     * Get Website ID by Store ID.
     *
     * @param int|string Store ID
     * @return int Website ID
     */
    private function getWebsiteIdByStoreID($storeId)
    {
        return (int) $this->storeManager->getStore($storeId)->getWebsiteId();
    }

    /**
     * Get all Website IDs
     *
     * @return array Website IDs
     */
    public function getWebsiteIds()
    {
        $ids = [];
        foreach ($this->websiteCollectionFactory->create() as $website) {
            $ids[] = (int) $website->getId();
        }
        return $ids;
    }

    private function getWebsiteIdForWebsiteName($websiteName) {
        foreach ($this->websiteCollectionFactory->create() as $website) {
            if ($website->getName() === $websiteName) {
                return (int) $website->getId();
            }
        }
        return null;
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
    public function getSmailyConfig($code, $websiteId = 0)
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

        return trim($this->getConfigValue(self::XML_PATH . $tab . '/' . $code, $websiteId));
    }

    /**
     * Get CAPTCHA type to use in newsletter signup form.
     *
     * @return string Captcha type.
     */
    public function getCaptchaType()
    {
        return $this->getSmailyConfig('captchaType');
    }

    /**
     * Get reCAPTCHA public API key.
     *
     * @return string public key.
     */
    public function getCaptchaApiKey()
    {
        return $this->getSmailyConfig('captchaApiKey');
    }

    /**
     * Get reCAPTCHA private API key.
     *
     * @return string private key.
     */
    public function getCaptchaApiSecretKey()
    {
        return $this->getSmailyConfig('captchaApiSecret');
    }

    /**
     * Get Smaily Subdomain by website ID.
     *
     * @return string
     */
    public function getSubdomainByWebsiteId($websiteId)
    {
        $domain = $this->getSmailyConfig('subdomain', $websiteId);

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
        $websiteId = $this->getCurrentWebsiteID();
        $autoresponders = $this->callApi('workflows', ['trigger_type' => 'form_submitted'], 'GET', $websiteId);
        $list = [];

        if (!empty($autoresponders)) {
            foreach ($autoresponders as $autoresponder) {
                    $list[$autoresponder['id']] = trim($autoresponder['title']);
            }
        }

        return $list;
    }

    /**
     * Get Subscribe/Import Customer to Smaily by email with OPT-IN trigger.
     *
     * @return array
     *  Smaily api response
     */
    public function optInSubscriber($email, $data = [], $websiteId = 0)
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

        return $this->callApi('autoresponder', $post, 'POST', $websiteId);
    }

    /**
     * Send newsletter subscribers to Smaily.
     *
     * @param array $list Subscribers list in batches.
     * @return boolean Success/Failure status
     */
    public function cronSubscribeAll($list)
    {
        $subscribers = [];
        foreach ($list as $batch) {
            // Subscribe customers to each website separately.
            foreach ($this->getWebsiteIds() as $websiteId) {
                if (! $this->isEnabledForWebsite($websiteId)) {
                    continue;
                }

                if (! $this->isCronEnabledForWebsite($websiteId)) {
                    continue;
                }

                // Filter subscribers by website ID.
                $subscribers = array_filter($batch, function ($subscriber) use ($websiteId) {
                    $subscriberWebsiteId = $this->getWebsiteIdForWebsiteName($subscriber['website']);
                    return ($subscriberWebsiteId === (int) $websiteId);
                });

                if (empty($subscribers)) {
                    continue;
                }

                $response = $this->callApi('contact', $subscribers, 'POST', $websiteId);
                if (!array_key_exists('message', $response) ||
                    array_key_exists('message', $response) && $response['message'] !== 'OK') {
                    return false;
                }
            };
        }
        return true;
    }

    /**
     * Call to Smaily Autoresponder api;
     *
     * @return void
     */
    public function cronAbandonedcart($orders)
    {
        $currentDate = strtotime(date('Y-m-d H:i') . ':00');
        foreach ($orders as $row) {
            $websiteId = $this->getWebsiteIdByStoreID($row['store_id']);
            if (! $this->isAbandonedCartEnabledForWebsite($websiteId)) {
                continue;
            }

            // Get sync interval and fields from website scope settings.
            $syncTime = str_replace(':', ' ', $this->getSmailyConfig('syncTime', $websiteId));
            $fields = explode(',', $this->getSmailyConfig('productfields', $websiteId));

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
                $response = $this->sendAbandonedCartEmail($preparedCart, $websiteId);
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
    public function sendAbandonedCartEmail($preparedCart, $websiteId)
    {
        $customerData = $preparedCart['customer_data'];
        $productsData = $preparedCart['products_data'];

        $autoRespId = $this->getSmailyConfig('autoresponderId', $websiteId);

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

        return $this->callApi('autoresponder', $query, 'POST', $websiteId);
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
     * Returns last customer synchronization update time from db.
     *
     * @return string/false Returns update time or false if not set.
     */
    public function getLastCustomerSyncTime()
    {
        if (!isset($this->connection)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->create('\Magento\Framework\App\ResourceConnection');
            $this->connection = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        }

        $table = $this->connection->getTableName('smaily_customer_sync');

        return $this->connection->fetchOne("SELECT last_update_at FROM $table");
    }

    /**
     * Updates customer sync timestamp when cron runs.
     *
     * @param string/boolean $last_update Last update time. False if first time.
     * @return void
     */
    public function updateCustomerSyncTimestamp($last_update)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        if (!isset($this->connection)) {
            $resource = $objectManager->create('\Magento\Framework\App\ResourceConnection');
            $this->connection = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        }
        $datetime = $objectManager->create('\Magento\Framework\Stdlib\DateTime\DateTime');
        $date = $datetime->gmtDate();

        $table = $this->connection->getTableName('smaily_customer_sync');
        if ($last_update) {
            $sql = "UPDATE $table SET last_update_at = :CURRENT_UTC_TIME";
        } else {
            $sql = "INSERT INTO $table (last_update_at) VALUES (:CURRENT_UTC_TIME)";
        }
        $binds = ['CURRENT_UTC_TIME' => $date];
        $this->connection->query($sql, $binds);
    }

     /**
      * Get Smaily unsubscribers emails.
      *
      * @param integer $limit Limit number of results.
      * @param integer $offset Page number (Not sql offset).
      * @param integer $websiteId Unsubscribers under this Website ID.
      * @return array Unsubscribers emails list from smaily.
      */
    public function getUnsubscribersEmails($limit, $offset = 0, $websiteId = 0)
    {
        $unsubscribers_emails = [];
        $data = [
            'list' => 2,
            'limit' => $limit,
        ];

        while (true) {
            $data['offset'] = $offset;
            $unsubscribers = $this->callApi('contact', $data, 'GET', $websiteId);

            if (!$unsubscribers) {
                break;
            }

            foreach ($unsubscribers as $unsubscriber) {
                $unsubscribers_emails[] = $unsubscriber['email'];
            }
            // Smaily api call offset is considered as page number, not sql offset!
            $offset++;
        }

        return $unsubscribers_emails;
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
    public function callApi($endpoint, $data = [], $method = 'GET', $websiteId = 0)
    {
        $response = [];
        // get smaily subdomain, username and password
        $subdomain = $this->getSubdomainByWebsiteId($websiteId);
        $username = $this->getSmailyConfig('username', $websiteId);
        $password = $this->getSmailyConfig('password', $websiteId);
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
