<?php

/**
 * Sendsmaily Sync
 * Module to export Magento newsletter subscribers to Sendsmaily
 * Copyright (C) 2010 Sendsmaily
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */
 
namespace Magento\Smaily\Controller\Cronjob;
use Magento\Framework\App\Action\Context;
 
class Index extends \Magento\Framework\App\Action\Action
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