<?php

namespace Smaily\SmailyForMagento\Model\Config\Source;

class CronSyncTime implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for Sync Time field.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $list = [
            [
                'value' => '0 */3 * * *',
                'label' => '3 hours',
            ],
            [
                'value' => '0 */6 * * *',
                'label' => '6 hours',
            ],
            [
                'value' => '0 */12 * * *',
                'label' => '12 hours',
            ],
            [
                'value' => '30 1 */1 * *',
                'label' => 'Once a day at 1:30',
            ],
            [
                'value' => '30 1 */2 * *',
                'label' => 'Once over 2 days at 1:30',
            ],
            [
                'value' => '30 1 */5 * *',
                'label' => 'Once over 5 days at 1:30',
            ],
            [
                'value' => '30 1 * * 1',
                'label' => 'At 1:30 on Monday',
            ],
            [
                'value' => '30 1 1 */1 *',
                'label' => 'Once a month',
            ]
        ];

        return $list;
    }
}