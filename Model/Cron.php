<?php

namespace Smaily\SmailyForMagento\Model;

use \Magento\Framework\App\ResourceConnection;
use \Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\SmailyForMagento\Helper\Data as Helper;
use Smaily\SmailyForMagento\Model\Cron\Orders;
use Smaily\SmailyForMagento\Model\Cron\Customers;

class Cron
{
    protected $customers;
    protected $date;
    protected $helperData;
    protected $orders;
    protected $resourceConnection;

    public function __construct(
        Customers $customers,
        DateTime $date,
        Helper $helperData,
        Orders $orders,
        ResourceConnection $resourceConnection
    ) {
        $this->customers = $customers;
        $this->date =$date;
        $this->helperData = $helperData;
        $this->orders = $orders;
        $this->resourceConnection = $resourceConnection;
    }

    public function subscriberSync()
    {
        if ($this->helperData->isCronEnabled()) {
            $writer = new \Zend\Log\Writer\Stream(BP. '/var/log/smly_customer_cron.log');
            $logger = new \Zend\Log\Logger();
            $logger->addWriter($writer);
            $logger->info('Running smaily customer synchronization!');
            // DB connection. Get last update time.
            $connection = $this->_resourceConnection->getConnection($this->_resourceConnection::DEFAULT_CONNECTION);
            $last_update = $connection->fetchOne("SELECT last_update_at FROM smaily_customer_sync");

            // import all customer to Smaily
            $subscribers = $this->customers->getList($last_update);
            if (!empty($subscribers)) {
                $response = $this->helperData->cronSubscribeAll($subscribers);
                if (array_key_exists('message', $response) && $response['message'] === 'OK') {
                    $logger->info(json_encode($response));
                } else {
                    $logger->info('Could not synchronize subscribers! - ' . json_encode($response));
                }
            } else {
                $logger->info('No updated subscribers since last sync!');
            }

            $this->updateCustomerSyncTimestamp($last_update);
        }
        return $this;
    }

    public function abandonedCartSync()
    {
        if ($this->helperData->isAbandonedCartEnabled()) {
            // Send abandoned cart data to smaily autoresponder
            $this->helperData->cronAbandonedcart($this->orders->getList());
        }
        return $this;
    }

    public function updateCustomerSyncTimestamp($last_update)
    {
        $date = $this->date->gmtDate();
        if ($last_update) {
            $sql = "UPDATE smaily_customer_sync SET last_update_at = :CURRENT_UTC_TIME";
        } else {
            $sql = "INSERT INTO smaily_customer_sync (last_update_at) VALUES (:CURRENT_UTC_TIME)";
        }
        $binds = ['CURRENT_UTC_TIME' => $date];
        $connection->query($sql, $binds);
    }
}
