<?php

namespace Smaily\SmailyForMagento\Setup;

use \Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\Setup\ModuleContextInterface;

class UpgradeSchema implements \Magento\Framework\Setup\UpgradeSchemaInterface
{
    /**
     * Run schema upgrade.
     *
     * @param \Magento\Framework\Setup\SchemaSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface $context
     * @access public
     * @return void
     */
    public function upgrade(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        if (version_compare($context->getVersion(), '2.0.0', '<')) {
            $this->migration001($installer);
        }
        if (version_compare($context->getVersion(), '2.3.0', '<')) {
            $this->migration002($installer);
        }

        $installer->endSetup();
    }

    /**
     * Run version 2.0.0 migrations.
     *
     * @param \Magento\Framework\Setup\SchemaSetupInterface $installer
     * @access private
     * @return void
     */
    private function migration001(SchemaSetupInterface $installer)
    {
        $customerSyncTableName = 'smaily_customer_sync';

        // Add ID column to customer synchronization table.
        if ($installer->tableExists($customerSyncTableName)) {
            $installer->getConnection()->addColumn(
                $installer->getTable($customerSyncTableName),
                'id',
                [
                    'comment' => 'ID',
                    'identity' => true,
                    'nullable' => false,
                    'primary' => true,
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_INTEGER,
                    'unsigned' => true,
                ]
            );
        }
    }

    /**
     * Run version 2.3.0 migrations.
     *
     * @param \Magento\Framework\Setup\SchemaSetupInterface $installer
     * @access private
     * @return void
     */
    private function migration002(SchemaSetupInterface $installer)
    {
        $customerSyncTableName = 'smaily_customer_sync';

        if ($installer->tableExists($customerSyncTableName)) {
            // Add Website column to customer synchronization table.
            $installer->getConnection()->addColumn(
                $installer->getTable($customerSyncTableName),
                'website_id',
                [
                    'comment' => 'Website ID',
                    'identity' => false,
                    'nullable' => false,
                    'primary' => false,
                    'type' => \Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT,
                    'unsigned' => true,
                ]
            );

            // Add unique index on Website column in customer synchronization table.
            $installer->getConnection()->addIndex(
                $installer->getTable($customerSyncTableName),
                $installer->getIdxName(
                    $customerSyncTableName,
                    ['website_id'],
                    \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                ),
                ['website_id'],
                \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
            );
        }
    }
}
