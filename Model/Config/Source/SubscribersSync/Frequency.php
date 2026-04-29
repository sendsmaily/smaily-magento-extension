<?php

namespace Smaily\SmailyForMagento\Model\Config\Source\SubscribersSync;

class Frequency implements \Magento\Framework\Data\OptionSourceInterface
{
    /**
     * Get options for Newsletter Subscribers synchronization frequencies.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => '0 */4 * * *',
                'label' => 'Every 4 hours',
            ],
            [
                'value' => '0 */12 * * *',
                'label' => 'Twice a day',
            ],
            [
                'value' => '0 0 * * *',
                'label' => 'Every day',
            ],
            [
                'value' => '0 0 * * 0',
                'label' => 'Once a week',
            ],
        ];
    }
}
