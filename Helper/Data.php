<?php

namespace Smaily\SmailyForMagento\Helper;

use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    protected $logger;

    const XML_PATH = 'smaily/';

    private $connection;

    public function __construct(
        Context $context,
        \Psr\Log\LoggerInterface $logger
    ) {
        parent::__construct(
            $context
        );

        $this->logger = $logger;
    }

    /**
     * Check  Smaily Extension is enabled
     *
     * @return bool
     */
    public function isEnabled()
    {
        return (bool) $this->getGeneralConfig('enable');
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
        if ($code === 'autoresponder_id') {
            $tab = 'subscribe';
        }
        if (in_array($code, ['fields', 'sync_period', 'enableCronSync'], true)) {
            $tab = 'sync';
        }
        if (in_array($code, ['ac_ar_id', 'sync_time', 'productfields', 'carturl', 'enableAbandonedCart'], true)) {
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
        $response = $this->callApi('autoresponder');
        $list = [];

        if (!empty($response)) {
            foreach ($response as $r) {
                if (!empty($r['id']) && !empty($r['name'])) {
                    $list[$r['id']] = trim($r['name']);
                }
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
     * Get Subscribe/Import Customer to Smaily by email with AutoResponder ID
     *
     * @return array
     *  Smaily api response
     */
    public function subscribeAutoresponder($aid, $email, $data = [])
    {
        $address = [
            'email' => $email,
        ];

        if (!empty($data)) {
            $fields = explode(',', $this->getGeneralConfig('fields'));
            foreach ($data as $field => $val) {
                if ($field === 'name' || in_array($field, $fields, true)) {
                    $address[$field] = trim($val);
                }
            }
        }

        $post = [
            'autoresponder' => $aid,
            'addresses' => [$address],
        ];

        return $this->callApi('autoresponder', $post, 'POST');
    }

    /**
     * Send newsletter subscribers to Smaily
     *
     * @return array
     *  Smaily api response
     */
    public function cronSubscribeAll($list)
    {
        $data = [];
        // Get unsubscribers from Smaily
        $unsubscribers = $this->getUnsubscribers();
        // Populate unsubscribers emails array
        $unsubscribers_emails= [];
        foreach ($unsubscribers as $unsubscriber) {
            if (isset($unsubscriber['email'])) {
                $unsubscribers_emails[] = $unsubscriber['email'];
            }
        }
        // Update only subscribers who are still subscribed
        foreach ($list as $row) {
            if (!in_array($row['email'], $unsubscribers_emails)) {
                $data[] = $row;
            }
        }
        return $this->callApi('contact', $data, 'POST');
    }

    /**
     * Call to Smaily email API;
     *
     * @return bool|array
     *  Smaily api response
     */
    public function autoResponderAPiEmail($_data, $emailProduct)
    {
        $autoRespId = $this->getGeneralConfig('ac_ar_id');
        $prod = @$emailProduct[0];

        $address = [
            'email' => $_data['email'],
            'name' => $_data['customer_name'],
            'abandoned_cart_url' => $this->getGeneralConfig('carturl'),
        ];
        $response = false;
        if (!empty($prod)) {
            foreach ($prod as $field => $val) {
                $address['product_' . $field] = $val;
            }

            $query = [
                'autoresponder' => $autoRespId,
                'addresses' => [$address],
            ];
            $response = $this->callApi('autoresponder', $query, 'POST');
        }
        return $response;
    }

    public function abandonedCartEmail($_data, $message)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        $transportBuilder = $objectManager->get('\Magento\Framework\Mail\Template\TransportBuilder');
        $store = $storeManager->getStore()->getId();
        $transport = $transportBuilder->setTemplateIdentifier('smaily_email_template')
            ->setTemplateOptions(['area' => 'frontend', 'store' => $store])
            ->setTemplateVars([
                'store' => $storeManager->getStore(),
                'data' => $message,
            ])
            ->setFrom('general')
            ->addTo($_data['email'], $_data['customer_name'])
            ->getTransport();
        return $transport->sendMessage();
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
                if (@$response['message'] == 'OK') {
                    // Update quote sent status
                    $this->updateSentStatus($quote_id);
                    // Log message
                    $result = 'Quote id: ' . $quote_id . ' > ' . ($response ? 'Sent' : 'Error');
                } else {
                    if (array_key_exists('error', $response)) {
                        $result = 'Quote id: ' . $quote_id . ' > ' . $response['message'];
                    }
                }
                // create log for api response.
                $writer = new \Zend\Log\Writer\Stream(BP. '/var/log/cronCart.log');
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
                    $_product[$field] = $val;
                }
            }
            $responderProduct[] = $_product;
        }

        $_data = [
            'customer_name' => $row['customer_firstname'],
            'email' => $row['customer_email'],
        ];
        return $this->autoResponderAPiEmail($_data, $responderProduct);
    }

    /**
     * Get Smaily unsubscribers
     *
     * @return array Unsubscribers list from smaily
     */
    public function getUnsubscribers()
    {
        $data = [
            'list' => 2,
        ];

        // Request unsubscribers from Smaily.
        return $this->callApi('contact', $data);
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
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $curl = $objectManager->create('\Magento\Framework\HTTP\Client\Curl');
        try {
            if ($method === 'GET') {
                $data = urldecode(http_build_query($data));
                $apiUrl = $apiUrl . '?' . $data;
                $curl->setCredentials($username, $password);
                $curl->get($apiUrl);

                $response = (array) json_decode($curl->getBody(), true);
            } elseif ($method === 'POST') {
                $curl->setCredentials($username, $password);
                $curl->post($apiUrl, $data);

                $response = (array) json_decode($curl->getBody(), true);

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
