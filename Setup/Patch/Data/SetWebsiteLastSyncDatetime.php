<?php

declare(strict_types=1);

namespace Smaily\SmailyForMagento\Setup\Patch\Data;

use Smaily\SmailyForMagento\Model\ResourceModel\SubscribersSyncState\Collection as SubscribersSyncStateCollection;

class SetWebsiteLastSyncDatetime implements \Magento\Framework\Setup\Patch\DataPatchInterface
{
    private $moduleDataSetup;

    private $configWriter;
    private $storeManager;

    private $subscribersSyncStateCollection;

    /**
     * @param \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Framework\App\Config\Storage\WriterInterface $configWriter,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        SubscribersSyncStateCollection $subscribersSyncStateCollection
    )
    {
        $this->moduleDataSetup = $moduleDataSetup;

        $this->configWriter = $configWriter;
        $this->storeManager = $storeManager;

        $this->subscribersSyncStateCollection = $subscribersSyncStateCollection;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $websites = $this->storeManager->getWebsites();

        $connection = $this->subscribersSyncStateCollection->getConnection();
        $tableName = $this->subscribersSyncStateCollection->getMainTable();

        // Get stored last update date.
        $select = $connection
            ->select()
            ->from($tableName, ['last_update_at'])
            ->limit(1);

        $lastSyncedAt = $connection->fetchRow($select);
        $lastSyncedAt = !empty($lastSyncedAt)
            ? (new \DateTimeImmutable($lastSyncedAt['last_update_at']))->format('Y-m-d H:i:s')
            : null;

        foreach ($websites as $website) {
            $this->configWriter->save(
                'smaily/sync/lastSyncedAt',
                $lastSyncedAt,
                \Magento\Store\Model\ScopeInterface::SCOPE_WEBSITES,
                $website->getId()
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return '2.3.0';
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }
}
