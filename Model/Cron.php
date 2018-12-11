<?php
namespace Smaily\SmailyForMagento\Model;

use \Psr\Log\LoggerInterface;
use Smaily\SmailyForMagento\Helper\Data as Helper;
use Smaily\SmailyForMagento\Model\Cron\Orders;
use \Smaily\SmailyForMagento\Model\Cron\Customers;

class Cron
{
    protected $objectManager;
    protected $helperData;
    protected $logger;
    protected $orders;
    protected $customers;

    public function __construct(
        LoggerInterface $logger,
        Customers $customers,
        Orders $orders,
        Helper $helperData
    ) {
        $this->helperData = $helperData;
        $this->logger = $logger;
        $this->customers = $customers;
        $this->orders = $orders;
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
            $logger->info(json_encode($response));
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
