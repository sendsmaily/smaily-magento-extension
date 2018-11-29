<?php

namespace Magento\Smaily\Controller\Cronjob;

class Customers extends \Magento\Framework\App\Action\Action
{

    public function execute()
    {
        // Get object Manager.
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        // Get customers for cron job.
        $customers = $objectManager->create('Magento\Smaily\Model\Cron\Customers');

        // Get Smaily Helper class.
        $helperData = $objectManager->create('Magento\Smaily\Helper\Data');

        // Export customer to Smaily.
        $helperData->cronSubscribeAll($customers->getList());
    }
}
