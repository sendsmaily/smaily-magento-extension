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

        // Add ID column to smaily_customer_sync table.
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
}
