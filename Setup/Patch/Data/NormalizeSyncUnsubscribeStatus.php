<?php

declare(strict_types=1);

namespace Smaily\SmailyForMagento\Setup\Patch\Data;

class NormalizeSyncUnsubscribeStatus implements \Magento\Framework\Setup\Patch\DataPatchInterface
{
    private $moduleDataSetup;
    private \Magento\Newsletter\Model\ResourceModel\Subscriber\Collection $newsletterSubscribersCollection;

    /**
     * @param \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup
     * @param \Magento\Newsletter\Model\ResourceModel\Subscriber\Collection $newsletterSubscribersCollection
     */
    public function __construct(
        \Magento\Framework\Setup\ModuleDataSetupInterface $moduleDataSetup,
        \Magento\Newsletter\Model\ResourceModel\Subscriber\Collection $newsletterSubscribersCollection,
    ) {
        $this->moduleDataSetup = $moduleDataSetup;

        $this->newsletterSubscribersCollection = $newsletterSubscribersCollection;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $this->newsletterSubscribersCollection
            ->getConnection()
            ->update(
                $this->newsletterSubscribersCollection->getMaintable(),
                [
                    'subscriber_status' => \Magento\Newsletter\Model\Subscriber::STATUS_UNSUBSCRIBED,
                ],
                [
                    'subscriber_status = ?' => 0,
                ]
            );
    }

    /**
     * @inheritdoc
     */
    public function getVersion()
    {
        return '2.6.0';
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
