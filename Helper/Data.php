<?php

namespace Smaily\SmailyForMagento\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use \Magento\Framework\HTTP\Client\Curl;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $logger;
    protected $curl;

    const XML_PATH = 'smaily/';

    private $connection;

    public function __construct(
        Context $context,
        \Psr\Log\LoggerInterface $logger,
        Curl $curl
    ) {
        parent::__construct(
            $context
        );

        $this->logger = $logger;
        $this->curl = $curl;
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
     * Check  Smaily Cron Sync is enabled
     *
     * @return bool
     */
    public function isCronEnabled()
    {
        return (bool) $this->getGeneralConfig('enableCronSync');
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
        if ($code === 'enableNewsletterSubscriptions') {
            $tab = 'subscribe';
        }
        if (in_array($code, ['fields', 'sync_period', 'enableCronSync'], true)) {
            $tab = 'sync';
        }
        if (in_array($code, ['ac_ar_id', 'sync_time', 'productfields', 'enableAbandonedCart'], true)) {
            $tab = 'abandoned';
        }
        if ($code === 'feed_token') {
            $tab = 'rss';
        }

        return trim($this->getConfigValue(self::XML_PATH . $tab . '/' . $code, $storeId));
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
     * Send newsletter subscribers to Smaily.
     *
     * @param array $list Subscribers list in batches.
     * @return boolean Success/Failure status
     */
    public function cronSubscribeAll($list)
    {
        foreach ($list as $batch) {
            $response = $this->callApi('contact', $batch, 'POST');
            if (!array_key_exists('message', $response) ||
                array_key_exists('message', $response) && $response['message'] !== 'OK') {
                    return false;
            }
        }

        return true;
    }

    /**
     * Call to Smaily email API;
     *
     * @return bool|array
     *  Smaily api response
     */
    public function sendAbandonedCartEmail($_data, $emailProduct)
    {
        // send data to autoresponder limit 10 products
        $autoRespId = $this->getGeneralConfig('ac_ar_id');
        $response = false;
        if (!empty($emailProduct) && !empty($_data)) {
            $address = [
                'email' => $_data['email'],
                'name' => $_data['customer_name'],
            ];
            //If more than one product in abandoned cart iterate to products array
            if (count($emailProduct) > 10) {
                $address['over_10_products'] = 'true';
            } elseif (count($emailProduct) > 1) {
                $length = count($emailProduct);
                if ($length > 10) {
                    $length = 10;
                }
                for ($i=0; $i < $length; $i++) {
                    foreach ($emailProduct[$i] as $key => $value) {
                        $itemNumber = $i + 1;
                        $address[$key . '_' . $itemNumber] = $value;
                    }
                }
            } else {
                foreach ($emailProduct[0] as $key => $val) {
                    $address[$key . '_1'] = $val;
                }
            }
            $query = [
                'autoresponder' => $autoRespId,
                'addresses' => [$address],
            ];
            $response = $this->callApi('autoresponder', $query, 'POST');
        }
        return $response;
    }

    /**
     * Call to Smaily Autoresponder api;
     *
     * @return void
     */
    public function cronAbandonedcart($orders)
    {
        // Get sync interval and fields from settings
        $sync_time = str_replace(':', ' ', $this->getGeneralConfig('sync_time'));
        $fields = explode(',', $this->getGeneralConfig('productfields'));
        $currentDate = strtotime(date('Y-m-d H:i') . ':00');
        foreach ($orders as $row) {
            // Quote id
            $quote_id = $row['quote_id'];
            // Is email sent
            $isSent = (int) $row['is_sent'] === 1 ? true : false;
            // Set remainder date if not already been set
            if (!empty($row['reminder_date'])) {
                $nextDate = strtotime($row['reminder_date']);
            } else {
                $nextDate = strtotime($sync_time, $currentDate);
                $this->updateReminderDate($quote_id, date('Y-m-d H:i:s', $nextDate));
                continue;
            }
            // Send remainder mail if reminder date has passed and mail not sent
            if ($currentDate >= $nextDate && !$isSent) {
                $reminderUpdate = strtotime($sync_time, $currentDate);
                // Send cart data to smaily autoresponder
                $response = $this->alertCustomer($row, $fields);
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

    private function alertCustomer($row, $fields)
    {
        $responderProduct = [];

        foreach ($row['products'] as $product) {
            $_product = [];
            foreach ($product as $field => $val) {
                if ($field === 'name' || in_array($field, $fields, true)) {
                    $_product['product_' . $field] = $val;
                }
            }
            $responderProduct[] = $_product;
        }

        $_data = [
            'customer_name' => $row['customer_firstname'],
            'email' => $row['customer_email'],
        ];
        return $this->sendAbandonedCartEmail($_data, $responderProduct);
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
      * @return array Unsubscribers emails list from smaily.
      */
    public function getUnsubscribersEmails($limit, $offset = 0)
    {
        $unsubscribers_emails = [];
        $data = [
            'list' => 2,
            'limit' => $limit,
        ];

        while (true) {
            $data['offset'] = $offset;
            $unsubscribers = $this->callApi('contact', $data);

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
