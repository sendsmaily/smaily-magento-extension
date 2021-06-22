<?php

namespace Smaily\SmailyForMagento\Model\ResourceModel;

class SubscribersSyncState extends \Magento\Framework\Model\ResourceModel\Db\AbstractDb
{
    /**
     * Resource model constructor.
     *
     * @access private
     * @return void
     */
    protected function _construct()
    {
        $this->_init('smaily_customer_sync', 'id');
    }
}
