<?php

namespace Magento\Smaily\Controller\Cronjob;

class Abandonedcart extends \Magento\Framework\App\Action\Action
{

    public function execute()
    {
        // Get object Manager.
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        // Get Abandoned orders for cron job.
        $orders = $objectManager->create('Magento\Smaily\Model\Cron\Orders');

        // Get Smaily Helper class.
        $helperData = $objectManager->create('Magento\Smaily\Helper\Data');

        $helperData->cronAbandonedcart($orders->getList());
    }
}
