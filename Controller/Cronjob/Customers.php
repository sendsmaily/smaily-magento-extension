<?php

namespace Magento\Smaily\Controller\Cronjob;

use Magento\Framework\App\Action\Context;
 
class Customers extends \Magento\Framework\App\Action\Action
{

    public function execute()
    {
        // Get object Manager
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        // Get customers for cron job
        $customers = $objectManager->create('Magento\Smaily\Model\Cron\Customers');

        // Get Smaily Helper class
        $helperData = $objectManager->create('Magento\Smaily\Helper\Data');

       /**
       * Export customer to Sendsmaily.
       *
       * @return Smaily API response
       */
        $response = $helperData->cronSubscribeAll($customers->getList());

        // display response
        echo json_encode($response);
        exit;
    }
}