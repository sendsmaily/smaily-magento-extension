<?php

namespace Magento\Smaily\Model\Config\Source;

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
        $autoresponders = $objectManager->create('Magento\Smaily\Helper\Data')->getAutoresponders();

        foreach ($autoresponders as $id => $name) {
            $list[] = ['value' => $id, 'label' => $name];
        }

        return $list;
    }
}
