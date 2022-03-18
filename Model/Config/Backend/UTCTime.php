<?php

namespace Smaily\SmailyForMagento\Model\Config\Backend;

class UTCTime extends \Magento\Framework\App\Config\Value
{
    /**
     * Normalize date value to UTC before storing.
     *
     * @access public
     * @return void
     */
    public function beforeSave()
    {
        $value = $this->getValue();

        if (!empty($value)) {
            $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
            $localeDate = $objectManager->create(\Magento\Framework\Stdlib\DateTime\TimezoneInterface::class);

            $tz = new \DateTimeZone($localeDate->getConfigTimezone($this->getScope(), $this->getScopeId()));
            $dt = new \DateTime($value, $tz);

            $this->setValue($dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        }

        parent::beforeSave();
    }
}
