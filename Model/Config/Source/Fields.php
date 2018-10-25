<?php

namespace Magento\Smaily\Model\Config\Source;

class Fields implements \Magento\Framework\Option\ArrayInterface
{
    // Get Additional fields list for Smaily
     public function toOptionArray(){

        $list = [
            ['value' => 'subscription_type', 'label' => 'Subscription Type'],
            ['value' => 'customer_group', 'label' => 'Customer Group'],
            ['value' => 'customer_id', 'label' => 'Customer ID'],
            ['value' => 'prefix', 'label' => 'Prefix'],
            ['value' => 'firstname', 'label' => 'Firstname'],
            ['value' => 'lastname', 'label' => 'Lastname'],
            ['value' => 'gender', 'label' => 'Gender'],
            ['value' => 'birthday', 'label' => 'Date Of Birth'],
            ['value' => 'website', 'label' => 'Website'],
            ['value' => 'store', 'label' => 'Store']
        ];

        return $list;
     }
}