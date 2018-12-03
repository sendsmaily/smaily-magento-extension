<?php
namespace Smaily\SmailyForMagento\Model;

use \Psr\Log\LoggerInterface;
use Smaily\SmailyForMagento\Helper\Data as Helper;
use \Smaily\SmailyForMagento\Model\Cron\Customers;

class Cron
{
    protected $objectManager;
    protected $helperData;
    protected $logger;
    protected $customers;

    public function __construct(LoggerInterface $logger, Customers $customers, Helper $helperData)
    {
        $this->helperData = $helperData;
        $this->logger = $logger;
        $this->customers = $customers;
    }

    public function runCron()
    {
        if ($this->helperData->isEnabled()) {
            // import all customer to Smaily
            $response = $this->helperData->cronSubscribeAll($this->customers->getList());

            // create log for api response.
            $writer = new \Zend\Log\Writer\Stream('/var/log/cron.log');
            $logger = new \Zend\Log\Logger();
            $logger->addWriter($writer);
            $logger->info(json_encode($response));
        }
        return $this;
    }
}
