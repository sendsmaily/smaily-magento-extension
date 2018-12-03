<?php

namespace Smaily\SmailyForMagento\Controller\Cronjob;

use \Magento\Framework\App\Action\Context;
use Smaily\SmailyForMagento\Model\Cron\Orders;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Abandonedcart extends \Magento\Framework\App\Action\Action
{
    protected $orders;
    protected $helper;

    public function __construct(Context $context, Orders $orders, Helper $helper)
    {
        $this->orders = $orders;
        $this->helper =  $helper;
        parent::__construct($context);
    }

    public function execute()
    {
        $this->helper->cronAbandonedcart($this->orders->getList());
    }
}
