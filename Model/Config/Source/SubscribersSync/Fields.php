<?php

namespace Smaily\SmailyForMagento\Model\Config\Source\SubscribersSync;

class Fields implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get options for Newsletter Subscribers synchronization fields.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'subscription_type',
                'label' => 'Subscription Type',
            ],
            [
                'value' => 'customer_group',
                'label' => 'Customer Group',
            ],
            [
                'value' => 'customer_id',
                'label' => 'Customer ID',
            ],
            [
                'value' => 'prefix',
                'label' => 'Prefix',
            ],
            [
                'value' => 'first_name',
                'label' => 'Firstname',
            ],
            [
                'value' => 'last_name',
                'label' => 'Lastname',
            ],
            [
                'value' => 'gender',
                'label' => 'Gender',
            ],
            [
                'value' => 'birthday',
                'label' => 'Date Of Birth',
            ]
        ];
    }
}
