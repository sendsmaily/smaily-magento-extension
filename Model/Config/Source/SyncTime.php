<?php

namespace Magento\Smaily\Model\Config\Source;

class SyncTime implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for time list.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $list = [
            [
                'value' => '1:hour',
                'label' => '1 Hour',
            ],
            [
                'value' => '2:hour',
                'label' => '2 Hour',
            ],
            [
                'value' => '3:hour',
                'label' => '3 Hour',
            ],
            [
                'value' => '4:hour',
                'label' => '4 Hour',
            ],
            [
                'value' => '5:hour',
                'label' => '5 Hour',
            ],
            [
                'value' => '6:hour',
                'label' => '6 Hour',
            ],
            [
                'value' => '1:day',
                'label' => '1 Day',
            ],
            [
                'value' => '2:day',
                'label' => '2 Days',
            ],
            [
                'value' => '3:day',
                'label' => '3 Days',
            ],
        ];

        return $list;
    }
}
