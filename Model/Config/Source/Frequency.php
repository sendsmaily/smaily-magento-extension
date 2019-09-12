<?php

namespace Smaily\SmailyForMagento\Model\Config\Source;

class Frequency implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for customer sync frequency fields.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $list = [
            [
                'value' => '0 */4 * * *',
                'label' => 'Every 4 hours',
            ],
            [
                'value' => '0 */12 * * *',
                'label' => 'Twice a day',
            ],
            [
                'value' => '0 * */1 * *',
                'label' => 'Every day',
            ],
            [
                'value' => '0 0 * * 0',
                'label' => 'Once a week',
            ],
        ];

        return $list;
    }
}
