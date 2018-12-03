<?php

namespace Smaily\SmailyForMagento\Model\Cron;

use \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerFactory;
use \Magento\Customer\Api\CustomerRepositoryInterfaceFactory;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Customers
{
    protected $subcriberFactory;
    protected $customerFactory;
    protected $customerRepository;
    protected $helperData;

    /**
     * Load objects
     */
    public function __construct(
        CollectionFactory $subcriberFactory,
        CustomerFactory $customerFactory,
        CustomerRepositoryInterfaceFactory $customerRepositoryFactory,
        Helper $helperData
    ) {
        $this->subcriberFactory = $subcriberFactory;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepositoryFactory->create();
        $this->helperData = $helperData;
    }

    /**
     * Get Customer/Subscribers list
     */
    public function getList()
    {
        $contact = [];
        $exists_ids = [];

        // get subscribers
        $subscribers = $this->subcriberFactory->create()->load();
        foreach ($subscribers as $s) {
            $customer_id = (int) $s->getData('customer_id');
            $customer = $customer_id ? $this->customerRepository->getById($customer_id) : false;
            if ($customer) {
                $exists_ids[] = $customer_id;
            }

            // get DOB
            $DOB = '';
            if ($customer) {
                $DOB = $customer->getDob();
                if (!empty($DOB)) {
                    $DOB .= ' 00:00';
                }
            }
            // create list
            $contact[] = [
                'email' => $s->getData('subscriber_email'),
                'name' => $customer ? ucfirst($customer->getFirstname()).' '.ucfirst($customer->getLastname()) : '',
                'subscription_type' => 'Subscriber',
                'customer_group' => $customer ? $this->helperData->getCustomerGroupName($customer->getGroupId()) : 'Guest',
                'customer_id' => $customer_id,
                'prefix' => $customer ? $customer->getPrefix() : '',
                'firstname' => $customer ? ucfirst($customer->getFirstname()) : '',
                'lastname' => $customer ? ucfirst($customer->getLastname()) : '',
                'gender' => $customer ? ($customer->getGender() == 2 ? 'Female' : 'Male') : '',
                'birthday' => $DOB,
                'website' => '',
               // 'store' => $customer ? $customer->getData('store_id') : '', error no 'store_id'
            ];
        }

        // get customers
        $customers = $this->customerFactory->create()
            ->addAttributeToSelect('*')
            ->addAttributeToSort('id', 'DESC')
            ->load();

        foreach ($customers as $c) {
            $customer_id = (int) $c->getId();
            if (!in_array($customer_id, $exists_ids)) {
                // create list
                $contact[] = [
                    'email'=>$c->getEmail(),
                    'name' => ucfirst($c->getFirstname()).' '.ucfirst($c->getLastname()),
                    'subscription_type' => 'Customer',
                    'customer_group' => $this->helperData->getCustomerGroupName($c->getGroupId()),
                    'customer_id' => $customer_id,
                    'prefix' => $c->getPrefix(),
                    'firstname' => ucfirst($c->getFirstname()),
                    'lastname' => ucfirst($c->getLastname()),
                    'gender' => $c->getGender() == 2 ? 'Female' : 'Male',
                    'birthday' => !empty($c->getDob()) ? $c->getDob() . ' 00:00' : '',
                    'website' => '',
                    'store' => $c->getData('store_id'),
                ];
            }
        }
        return $contact;
    }
}
