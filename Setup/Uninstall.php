<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Removes the module's settings and flag rows when the module is
 * uninstalled, as the WooCommerce plugin does: every `smaily_connect/*`
 * configuration row at every scope (the encrypted Smaily password and the
 * Campaign Intelligence key included), the 2.8.x `smaily/*` rows the
 * upgrade kept for a downgrade (a plaintext password among them), and every
 * `smaily_connect_*` flag row (the profiling opt-out record, the verified
 * credentials, the health and reconcile cursors). No engine call is made:
 * the engine key stays valid on the engine until it is revoked there.
 *
 * Magento calls this on `bin/magento module:uninstall` (composer installs).
 * `module:uninstall --non-composer` (app/code installs) calls only the data
 * patch reverts, so RemoveSettingsOnUninstall::revert() runs the same
 * removal. Disabling the module calls neither. The module's tables are
 * Magento's: declarative schema drops them when the module is disabled.
 */
class Uninstall implements UninstallInterface
{
    /**
     * @inheritDoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->removeSettings(
            $setup->getConnection(),
            $setup->getTable('core_config_data'),
            $setup->getTable('flag')
        );
    }

    /**
     * Delete the module's config rows (every scope) and flag rows.
     */
    public function removeSettings(AdapterInterface $connection, string $configTable, string $flagTable): void
    {
        // "_" is a LIKE wildcard; escaped so only our own prefix matches.
        $connection->delete($configTable, ['path LIKE ?' => 'smaily\_connect/%']);
        $connection->delete($configTable, ['path LIKE ?' => 'smaily/%']);
        $connection->delete($flagTable, ['flag_code LIKE ?' => 'smaily\_connect\_%']);
    }
}
