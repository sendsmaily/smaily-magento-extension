<?php

namespace Smaily\SmailyForMagento\Model;

use Smaily\SmailyForMagento\Helper\Data as Helper;
use Smaily\SmailyForMagento\Model\Cron\Orders;
use Smaily\SmailyForMagento\Model\Cron\Customers;

const UNSUBSCRIBERS_BATCHES_LIMIT = 1000;

class Cron
{
    protected $customers;
    protected $helperData;
    protected $orders;

    public function __construct(
        Customers $customers,
        Helper $helperData,
        Orders $orders
    ) {
        $this->customers = $customers;
        $this->helperData = $helperData;
        $this->orders = $orders;
    }

    public function subscriberSync()
    {
        if ($this->helperData->isCronEnabled()) {
            $writer = new \Zend\Log\Writer\Stream(BP. '/var/log/smly_customer_cron.log');
            $logger = new \Zend\Log\Logger();
            $logger->addWriter($writer);
            $logger->info('Running smaily customer synchronization!');
            // Get last update time.
            $last_update = $this->helperData->getLastCustomerSyncTime();
            // Remove unsubscribers from Magento store.
            $unsubscribers_list = $this->helperData->getUnsubscribersEmails(UNSUBSCRIBERS_BATCHES_LIMIT);
            $this->customers->removeUnsubscribers($unsubscribers_list);

            // Import all customer to Smaily. List is in batches.
            $subscribers_list = $this->customers->getList($last_update);
            if (empty($subscribers_list)) {
                $logger->info('No updated subscribers since last sync!');
            } else {
                $success = $this->helperData->cronSubscribeAll($subscribers_list);
                if ($success) {
                    $logger->info('Customer synchronization successful!');
                    $this->helperData->updateCustomerSyncTimestamp($last_update);
                } else {
                    $logger->info('Could not synchronize all subscribers!');
                }
            }
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
}
