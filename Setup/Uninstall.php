<?php
namespace Mageplaza\HelloWorld\Setup;

use \Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\Setup\ModuleContextInterface;

class Uninstall implements \Magento\Framework\Setup\UninstallInterface
{
    /**
     * Run module uninstall logic.
     *
     * @param \Magento\Framework\Setup\SchemaSetupInterface $setup
     * @param \Magento\Framework\Setup\ModuleContextInterface $context
     * @access public
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        // Ensure subscribers synchronization state table is dropped.
        $installer->getConnection()->dropTable($installer->getTable('smaily_customer_sync'));

        // Ensure columns in quote table are dropped.
        $installer->getConnection()->dropColumn(
            $installer->getTable('quote'),
            'reminder_date'
        );
        $installer->getConnection()->dropColumn(
            $installer->getTable('quote'),
            'is_sent'
        );

        $installer->endSetup();
    }
}
