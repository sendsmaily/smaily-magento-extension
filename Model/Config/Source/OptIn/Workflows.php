<?php

namespace Smaily\SmailyForMagento\Model\Config\Source\OptIn;

use Smaily\SmailyForMagento\Helper\Data;

class Workflows implements \Magento\Framework\Option\ArrayInterface
{
    protected $dataHelper;

    /**
     * Class constructor.
     *
     * @access public
     * @return void
     */
    public function __construct(
        Data $dataHelper
    ) {
        $this->dataHelper = $dataHelper;
    }

    /**
     * Get Option values for AutoResponder ID field.
     *
     * @access public
     * @return array
     */
    public function toOptionArray()
    {
        $websiteId = $this->dataHelper->getConfigurationCurrentWebsiteId();

        $workflows = [
            ['value' => '', 'label' => 'No automation workflow selected - opt-in workflows are triggered'],
        ];

        foreach ($this->dataHelper->getAutomationWorkflows($websiteId) as $workflow) {
            $workflows[] = [
                'value' => $workflow['id'],
                'label' => $workflow['title'],
            ];
        }

        return $workflows;
    }
}
