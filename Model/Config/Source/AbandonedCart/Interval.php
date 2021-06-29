<?php

namespace Smaily\SmailyForMagento\Model\Config\Source\AbandonedCart;

class Interval implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get options for Abandoned Cart abandon intervals.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => '20:minutes',
                'label' => '20 Minutes',
            ],
            [
                'value' => '30:minutes',
                'label' => '30 Minutes',
            ],
            [
                'value' => '40:minutes',
                'label' => '40 Minutes',
            ],
            [
                'value' => '50:minutes',
                'label' => '50 Minutes',
            ],
            [
                'value' => '1:hour',
                'label' => '1 Hour',
            ],
            [
                'value' => '2:hour',
                'label' => '2 Hours',
            ],
            [
                'value' => '3:hour',
                'label' => '3 Hours',
            ],
            [
                'value' => '6:hour',
                'label' => '6 Hours',
            ],
            [
                'value' => '12:hour',
                'label' => '12 Hours',
            ],
        ];
    }
}
