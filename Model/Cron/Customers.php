<?php

namespace Magento\Smaily\Model\Cron;

class Customers
{
    protected $subcriberFactory;
    protected $customerFactory;
    protected $customerRepository;

    /**
     * Load objects
     */
    public function __construct(
        \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory $subcriberFactory,
        \Magento\Customer\Model\ResourceModel\Customer\CollectionFactory $customerFactory,
        \Magento\Customer\Api\CustomerRepositoryInterfaceFactory $customerRepositoryFactory
    ) {
        $this->subcriberFactory = $subcriberFactory;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepositoryFactory->create();
    }

    /**
     * Get Customer/Subscribers list
     */
    public function getList()
    {
        $contact = [];
        $exists_ids = [];

        // Load Smaily helper class
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $helperData = $objectManager->create('Magento\Smaily\Helper\Data');

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
                'customer_group' => $customer ? $helperData->getCustomerGroupName($customer->getGroupId()) : 'Guest',
                'customer_id' => $customer_id,
                'prefix' => $customer ? $customer->getPrefix() : '',
                'firstname' => $customer ? ucfirst($customer->getFirstname()) : '',
                'lastname' => $customer ? ucfirst($customer->getLastname()) : '',
                'gender' => $customer ? ($customer->getGender() == 2 ? 'Female' : 'Male') : '',
                'birthday' => $DOB,
                'website' => '',
                'store' => $customer ? $customer->getData('store_id') : '',
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
                    'customer_group' => $helperData->getCustomerGroupName($c->getGroupId()),
                    'customer_id' => $customer_id,
                    'prefix' => $c->getPrefix(),
                    'firstname' => ucfirst($c->getFirstname()),
                    'lastname' =>  ucfirst($c->getLastname()),
                    'gender' =>  $c->getGender() == 2 ? 'Female' : 'Male',
                    'birthday' => !empty($c->getDob()) ? $c->getDob().' 00:00' : '',
                    'website' => '',
                    'store' => $c->getData('store_id'),
                ];
            }
        }
        return $contact;
    }
}
