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

namespace Magento\Smaily\Model\Cron;

class Customers
{
	protected $subcriberFactory;
	protected $customerFactory;
	protected $customerRepository;

	// load objects
	public function __construct(
        \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subcriberFactory,
		\Magento\Customer\Model\ResourceModel\Customer\CollectionFactory $customerFactory,
	    \Magento\Customer\Api\CustomerRepositoryInterfaceFactory $customerRepositoryFactory
    ){
        $this->subcriberFactory = $subcriberFactory;
	    $this->customerFactory = $customerFactory; 
		$this->customerRepository = $customerRepositoryFactory->create();
    }  
	
	// Get Customer/Subscribers list
    public function getList($limit=500){
		
		$list = [];
		$exists_ids = [];
		
		// Load Smaily helper class
	 	$objectManager = \Magento\Framework\App\ObjectManager::getInstance();
		$helperData = $objectManager->create('Magento\Smaily\Helper\Data');

		// get subscribers
		$subscribers = $this->subcriberFactory->create()->setPageSize($limit)->load();
		foreach($subscribers as $s){
			
			$customer_id = (int)$s->getData('customer_id');
			$customer = $customer_id ? $this->customerRepository->getById($customer_id) : false;
         	if( $customer ) 
				$exists_ids[] = $customer_id;
			
			// get DOB	 
			$DOB = '';	 
			if( $customer ){
				$DOB =  $customer->getDob();
				if( !empty($DOB) )
					$DOB = $DOB.' 00:00';	 	
			}
			// create list
			$list[] = [
				'email'=>$s->getData('subscriber_email'),
				'name' => $customer ? ucfirst($customer->getFirstname()).' '.ucfirst($customer->getLastname()) : '',
				'subscription_type' => 'Subscriber',
				'customer_group' =>   $customer ? $helperData->getCustomerGroupName($customer->getGroupId()) : 'Guest',
				'customer_id' => $customer_id,
				'prefix' => $customer ? $customer->getPrefix() : '',
				'firstname' => $customer ? ucfirst($customer->getFirstname()) : '',
				'lastname' =>  $customer ? ucfirst($customer->getLastname()) : '',
				'gender' =>  $customer ? ($customer->getGender() == 2 ? 'Female' : 'Male') : '',
				'birthday' => $DOB,
				'website' => '', 
				'store' => $customer ? $customer->getData('store_id') : '',
			];
		}
		
		// get customers
		$customers = $this->customerFactory->create()
            ->addAttributeToSelect("*")
			->addAttributeToSort('id', 'DESC')
            ->setPageSize($limit)
			->load();
			
		foreach($customers as $c){
				
			$customer_id = (int)$c->getId();
			if( !in_array($customer_id,$exists_ids) ){
			
				// create list
				$list[] = [
					'email'=>$c->getEmail(),
					'name' => ucfirst($c->getFirstname()).' '.ucfirst($c->getLastname()),
					'subscription_type' => 'Customer',
					'customer_group' => $helperData->getCustomerGroupName($c->getGroupId()),
					'customer_id' => $customer_id,
					'prefix' => $c->getPrefix(),
					'firstname' => ucfirst($c->getFirstname()),
					'lastname' =>  ucfirst($c->getLastname()),
					'gender' =>  $c->getGender() == 2 ? "Female" : "Male",
					'birthday' => !empty($c->getDob()) ? $c->getDob()." 00:00" : '',
					'website' => '',
					'store' => $c->getData('store_id'),
				];
			
			}
		}
        return $list;
    }
}
