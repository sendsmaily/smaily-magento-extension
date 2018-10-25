<?php

namespace Magento\Smaily\Model;

use \Psr\Log\LoggerInterface;
use \Magento\Smaily\Model\Cron\Customers;

class Cron
{
    protected $objectManager;
    protected $helperData;

    protected $logger;
    protected $customers;

    // load objects
    public function __construct(
        LoggerInterface $logger,
        Customers $customers
       ) {

        $this->objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $helperData = $this->objectManager->create('Magento\Smaily\Helper\Data');
        $this->helperData = $helperData;

        $this->logger = $logger;
        $this->customers = $customers;
    }

    // call cron function
    public function runCron()
    {
        if ( $this->helperData->isEnabled() ){

            // import all customer to Smaily
            $response = $this->helperData->cronSubscribeAll($this->customers->getList());

            // create log for api response.
            $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/cron.log');
            $logger = new \Zend\Log\Logger();
            $logger->addWriter($writer);
            $logger->info(json_encode($response));
        }
        return $this;
    }
}