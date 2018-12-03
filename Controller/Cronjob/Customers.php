<?php

namespace Smaily\SmailyForMagento\Controller\Cronjob;

use \Magento\Framework\App\Action\Context;
use Smaily\SmailyForMagento\Model\Cron\Customers as CronCustomers;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Customers extends \Magento\Framework\App\Action\Action
{
    private $customers;
    private $helper;

    public function __construct(Context $context, CronCustomers $customers, Helper $helper)
    {
        $this->customers = $customers;
        $this->helper = $helper;
        parent::__construct($context);
    }

    public function execute()
    {
        // Export customer to Smaily.
        $this->helper->cronSubscribeAll($this->customers->getList());
    }
}
