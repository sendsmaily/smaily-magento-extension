<?php

namespace Smaily\SmailyForMagento\Model;

class SubscribersSyncState extends \Magento\Framework\Model\AbstractModel
{
    /**
     * Model constructor.
     *
     * @access protected
     * @return void
     */
    protected function _construct()
    {
        $this->_init('Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState');
    }
}
