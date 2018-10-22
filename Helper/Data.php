<?php

namespace Magento\Smaily\Helper;

use Magento\Store\Model\ScopeInterface;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{

    const XML_PATH = 'smaily/';

    private $connection;

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
     * Get Magento main configuration by field
     *
     * @return string
     */
    public function getConfigValue($config_path, $storeId = null)
    {
        return $this->scopeConfig->getValue($config_path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function updateReminderDate($quote_id, $reminderDate)
    {
        if (!isset($this->connection)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $resource = $objectManager->create('\Magento\Framework\App\ResourceConnection');
            $this->connection = $resource->getConnection(\Magento\Framework\App\ResourceConnection::DEFAULT_CONNECTION);
        }

        $table = 'quote';
        $sql = "Update $table Set reminder_date = '$reminderDate' where entity_id = '$quote_id'";

        return $this->connection->exec($sql);
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
        if (in_array($code, ['fields', 'sync_period'], true)) {
            $tab = 'sync';
        }
        if (in_array($code, ['ac_ar_id', 'sync_time', 'productfields', 'carturl'], true)) {
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

        if (empty($_SESSION['Smaily_customergroups'])) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $customerGroups = $objectManager->get('\Magento\Customer\Model\ResourceModel\Group\Collection');

            foreach ($customerGroups->toOptionArray() as $opt) {
                $list[(int) $opt['value']] = trim($opt['label']);
            }
            $_SESSION['Smaily_customergroups'] = $list;

        } else {
            $list = (array) $_SESSION['Smaily_customergroups'];
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
        if (empty($_SESSION['Smaily_autoresponder'])) {
            $_list = $this->callApi('autoresponder', ['status' => ['ACTIVE']]);
            $list = [];
            foreach ($_list as $r) {
                if (!empty($r['id']) && !empty($r['name'])) {
                    $list[$r['id']] = trim($r['name']);
                }
            }
            $_SESSION['Smaily_autoresponder'] = $list;

        } else {
            $list = (array) $_SESSION['Smaily_autoresponder'];
        }

        return $list;
    }

    /**
     * Subscribe/Import Customer to Smaily by email
     *
     * @return Smaily api response
     */
    public function subscribe($email, $data = [], $update = 0)
    {
        $address = [
            'email'=>$email,
            'is_unsubscribed' => $update
        ];

        if (!empty($data)) {
            $fields = explode(',', trim($this->getGeneralConfig('fields')));

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
     * @return Smaily api response
     */
    public function subscribeAutoresponder($aid, $email, $data = [])
    {
        $address = [
            'email'=>$email,
        ];

        if (!empty($data)) {
            $fields = explode(',', trim($this->getGeneralConfig('fields')));
            foreach ($data as $field => $val) {
                if ($field === 'name' || in_array($field, $fields, true)) {
                    $address[$field] = trim($val);
                }
            }
        }

        $post  = [
            'autoresponder' => $aid,
            'addresses' => [$address],
        ];

        return $this->callApi('autoresponder', $post, 'POST');
    }

    /**
     * Get Subsbribe/Import all Customers to Smaily by array list
     *
     * @return Smaily api response
     */
    public function cronSubscribeAll($list)
    {
        $data = [];
        $fields = explode(',', trim($this->getGeneralConfig('fields')));

        foreach ($list as $row) {
            $_data = [
                'email' => $row['email'],
                'is_unsubscribed' => 0
            ];

            foreach ($row as $field => $val) {
                if (in_array($field, $fields, true)) {
                    $_data[$field] = trim($val);
                }
            }

            $data[] = $_data;
        }

        return $this->callApi('contact', $data, 'POST');
    }

    /**
     * Call to Smaily email API;
     *
     * @return success
     */
    public function autoResponderAPiEmail($_data, $emailProduct)
    {
        $autoRespId = $this->getGeneralConfig('ac_ar_id');
        $prod= @$emailProduct[0];

        $address  =array(
            'email' => $_data['email'],
            'name' => $_data['customer_name'],
            'abandoned_cart_url' => $this->getGeneralConfig('carturl'),
        );
        $response = false;
        if (!empty($prod)) {
            foreach ($prod as $field => $val) {
                $address['product_'.$field] = $val;
            }

            $query = array(
              'autoresponder' => $autoRespId,
              'addresses' => array($address),
            );
            $response = $this->callApi('autoresponder', $query, 'POST');
        }
        return $response;
    }

    public function abandonedCartEmail($_data, $message)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $storeManager = $objectManager->get('\Magento\Store\Model\StoreManagerInterface');
        $transportBuilder =$objectManager->get('\Magento\Framework\Mail\Template\TransportBuilder');
        $store = $storeManager->getStore()->getId();
        $transport = $transportBuilder->setTemplateIdentifier('smaily_email_template')
            ->setTemplateOptions(['area' => 'frontend', 'store' => $store])
            ->setTemplateVars(['store' => $storeManager->getStore(), 'data'  => $message])
            ->setFrom('general')
            ->addTo($_data['email'], $_data['customer_name'])
            ->getTransport();
        return $transport->sendMessage();
    }

    /**
     * Call to Smaily Autoresponder api;
     *
     * @return success
     */
    public function cronAbandonedcart($orders)
    {
        $sync_time = str_replace(':', ' ', $this->getGeneralConfig('sync_time'));
        $fields = explode(',', trim($this->getGeneralConfig('productfields')));

        $currentDate = strtotime(date('Y-m-d H').':00:00');

        $notifyOnce = false;

        $data = [];
        $messageData = [];

        foreach ($orders as $row) {
            $quote_id = $row['quote_id'];
            $nextDate = !empty($row['reminder_date']) ? strtotime($row['reminder_date']) : $currentDate;

            if ((!$notifyOnce && $currentDate >= $nextDate) || ($notifyOnce && empty($row['reminder_date']))) {
                $reminderUpdate = strtotime($sync_time, $currentDate);

                $response = $this->alertCustomer($row, $fields);

                if (@$response['message'] == 'OK') {
                    $this->updateReminderDate($quote_id, date('Y-m-d H:i:s', $reminderUpdate));
                }

                echo $quote_id. ' : '.($response  ? 'Sent' : 'Error').'<br>';
            }
        }
        echo 'DONE';
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
     * Call to Smaily API
     *
     * @return array
     */
    public function callApi($endpoint, $data = [], $method = 'GET')
    {
        // get smaily subdomain, username and password
        $subdomain = $this->getSubdomain();
        $username = trim($this->getGeneralConfig('username'));
        $password = trim($this->getGeneralConfig('password'));

        // create api url
        $apiUrl = "https://$subdomain.sendsmaily.net/api/$endpoint.php";

        // create api post data
        $data = http_build_query($data);
        if ($method === 'GET') {
            $apiUrl = "$apiUrl?$data";
        }

        // curl call
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        curl_setopt($ch, CURLOPT_USERPWD, "$username:$password");

        // get result
        $result = json_decode(@curl_exec($ch), true);

        // check error
        if (curl_errno($ch)) {
            $result = ['code' => 0, 'message' => curl_error($ch)];
        }

        curl_close($ch);

        return $result;
    }
}
