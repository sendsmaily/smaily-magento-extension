<?php

namespace Smaily\SmailyForMagento\Model;

use Smaily\SmailyForMagento\Helper\Data as Helper;
use Smaily\SmailyForMagento\Model\Cron\Orders;
use \Smaily\SmailyForMagento\Model\Cron\Customers;

class Cron
{
    protected $helperData;
    protected $orders;
    protected $customers;

    public function __construct(
        Helper $helperData,
        Orders $orders,
        Customers $customers
    ) {
        $this->helperData = $helperData;
        $this->orders = $orders;
        $this->customers = $customers;
    }

    public function subscriberSync()
    {
        if ($this->helperData->isCronEnabled()) {
            // import all customer to Smaily
            $response = $this->helperData->cronSubscribeAll($this->customers->getList());
            // create log for api response.
            $writer = new \Zend\Log\Writer\Stream(BP. '/var/log/cron.log');
            $logger = new \Zend\Log\Logger();
            $logger->addWriter($writer);
            if (array_key_exists('message', $response) && $response['message'] === 'OK') {
                $logger->info(json_encode($response));
            } else {
                $logger->info('Could not synchronize subscribers!');
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
