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