<?php

namespace Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $dateTime;

    /**
     * Collection constructor.
     *
     * @access protected
     * @return void
     */
    protected function _construct()
    {
        $this->_init(
            'Smaily\SmailyForMagento\Model\SubscribersSyncState',
            'Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState'
        );

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->dateTime = $objectManager->create('\Magento\Framework\Stdlib\DateTime\DateTime');
    }

    /**
     * Return last synchronization time.
     *
     * @access public
     * @return \DateTimeImmutable|null
     */
    public function getLastSyncedAt()
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable(), array('last_update_at'))
            ->limit(1);

        $data = $this->getConnection()->fetchRow($select);
        if (empty($data)) {
            return null;
        }

        return new \DateTimeImmutable($data['last_update_at']);
    }

    /**
     * Update last synchronization time.
     *
     * @param \DateTimeImmutable $syncAt
     * @access public
     * @return void
     */
    public function updateLastSyncedAt(\DateTimeImmutable $syncAt)
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            array(
                'id' => 1,
                'last_update_at' => $this->dateTime->gmtDate(null, $syncAt),
            ),
            array('last_update_at'));
    }
}
