<?php

namespace Smaily\SmailyForMagento\Model\Cron;

use \Magento\Newsletter\Model\ResourceModel\Subscriber\CollectionFactory;
use \Magento\Customer\Api\CustomerRepositoryInterfaceFactory;
use Smaily\SmailyForMagento\Helper\Data as Helper;

class Customers
{
    protected $subcriberFactory;
    protected $customerRepository;
    protected $helperData;

    /**
     * Load objects
     */
    public function __construct(
        CollectionFactory $subcriberFactory,
        CustomerRepositoryInterfaceFactory $customerRepositoryFactory,
        Helper $helperData
    ) {
        $this->subcriberFactory = $subcriberFactory;
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

        // get only subscribers filtered by status 1 => subscribed
        $subscribers = $this->subcriberFactory->create()
            ->addFieldToFilter('subscriber_status', array('eq'=> 1))
            ->load();
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

            // get fields to sync from configuration page
            $sync_fields = $this->helperData->getGeneralConfig('fields');
            $sync_fields = explode(',', $sync_fields);

            // create list with subscriber data
            $subscriberData = array(
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
            );

            // Update values only selected in configuration page
            $subscriber = array();
            foreach ($subscriberData as $key => $value) {
                if ($key === 'email' || $key === 'name' || in_array($key, $sync_fields)) {
                    $subscriber[$key] = $value;
                }
            }
            $contact[] = $subscriber;
        }
        return $contact;
    }
}
