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
        $writer = new \Zend\Log\Writer\Stream(BP. '/var/log/smly_customer_cron.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        $logger->info('Running smaily customer synchronization!');
        // Get last update time.
        $last_update = $this->helperData->getLastCustomerSyncTime();

        // Remove unsubscribers from each Magento website separately.
        foreach ($this->helperData->getWebsiteIds() as $websiteId) {
            if (! $this->helperData->isEnabledForWebsite($websiteId)) {
                continue;
            }

            if (! $this->helperData->isCronEnabledForWebsite($websiteId)) {
                continue;
            }

            $unsubscribers_list = $this->helperData->getUnsubscribersEmails(1000, 0, $websiteId);
            $this->customers->removeUnsubscribers($unsubscribers_list, $websiteId);
        }

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
        return $this;
    }

    public function abandonedCartSync()
    {
        $this->helperData->cronAbandonedcart($this->orders->getList());
        return $this;
    }
}
