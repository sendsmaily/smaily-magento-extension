<?php

namespace Smaily\SmailyForMagento\Setup;

use \Magento\Framework\Setup\ModuleContextInterface;
use \Magento\Framework\Setup\SchemaSetupInterface;

class InstallSchema implements \Magento\Framework\Setup\InstallSchemaInterface
{
    /**
     * Run module installation logic.
     *
     * @param \Magento\Framework\Setup\SchemaSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface $context
     * @access public
     * @return void
     */
    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        // Ensure smaily_customer_sync table exists.
        if (!$installer->tableExists('smaily_customer_sync')) {
            $table = $installer->getConnection()->newTable(
                $installer->getTable('smaily_customer_sync')
            )
                ->addColumn(
                    'last_update_at',
                    \Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP,
                    null,
                    ['nullable' => true, 'default' => null],
                    'Last update time'
                );
            $installer->getConnection()->createTable($table);
        }

        // Add sent status and reminder date columns to quote table.
        $installer->getConnection()->addColumn(
            $installer->getTable('quote'),
            'reminder_date',
            [
                'type' => \Magento\Framework\DB\Ddl\Table::TYPE_DATETIME,
                'nullable' => true,
                'comment' => 'Reminder Date'
            ]
        );
        $installer->getConnection()->addColumn(
            $installer->getTable('quote'),
            'is_sent',
            [
                'type' => \Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN,
                'nullable' => true,
                'default' => 0,
                'comment' => 'Email sent'
            ]
        );

        $installer->endSetup();
    }
}
