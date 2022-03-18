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
            \Smaily\SmailyForMagento\Model\SubscribersSyncState::class,
            \Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState::class
        );

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $this->dateTime = $objectManager->create(\Magento\Framework\Stdlib\DateTime\DateTime::class);
    }

    /**
     * Return last synchronization time.
     *
     * @param integer $websiteId
     * @access public
     * @return \DateTimeImmutable|null
     */
    public function getLastSyncedAt($websiteId)
    {
        $select = $this->getConnection()
            ->select()
            ->from($this->getMainTable(), ['last_update_at'])
            ->where('website_id = ?', $websiteId)
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
     * @param integer $websiteId
     * @param mixed $syncAt
     * @access public
     * @return void
     */
    public function updateLastSyncedAt($websiteId, $syncAt)
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getMainTable(),
            [
                'website_id' => $websiteId,
                'last_update_at' => $syncAt !== null ? $this->dateTime->gmtDate(null, $syncAt) : null,
            ],
            ['last_update_at']
        );
    }
}
