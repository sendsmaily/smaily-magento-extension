<?php
namespace Mageplaza\HelloWorld\Setup;

use Magento\Framework\Setup\UninstallInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\ModuleContextInterface;

class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $installer = $setup;
        $installer->startSetup();

        $installer->getConnection()->dropTable($installer->getTable('smaily_customer_sync'));

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
