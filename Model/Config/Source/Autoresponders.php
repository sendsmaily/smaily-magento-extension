<?php

namespace Smaily\SmailyForMagento\Model\Config\Source;

class Autoresponders implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for AutoResponder ID field.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $list = [];

        // load object manager object
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

        // get autoresponder IDs list from Smaily
        $autoresponders = $objectManager->create('Smaily\SmailyForMagento\Helper\Data')->getAutoresponders();
        foreach ($autoresponders as $id => $title) {
            $list[] = ['value' => $id, 'label' => $title];
        }

        // For visual reffrence in form.
        $list[] = ['value' => '', 'label' => '- SELECT AUTORESPONDER -'];
        return $list;
    }
}
