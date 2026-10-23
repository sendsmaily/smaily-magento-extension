<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Engine\Disconnector;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Removes the module's settings and flag rows when the module is
 * uninstalled, as the WooCommerce plugin does: every `smaily_connect/*`
 * configuration row at every scope (the encrypted Smaily password and the
 * Campaign Intelligence key included), any 2.8.x `smaily/*` row still
 * present (the upgrade deletes them once migrated), and every
 * `smaily_connect_*` flag row (the profiling opt-out record, the verified
 * credentials, the health and reconcile cursors, the order-origin stamps), and the install's "ready
 * to set up" admin notice. Before that, endEngineConnection() tells
 * Campaign Intelligence that this store's connection ends (contract §16,
 * Disconnector): the engine pauses the store and keeps its data. A refused
 * key or an engine that does not answer never stops the removal.
 *
 * Magento calls this on `bin/magento module:uninstall` (composer installs).
 * `module:uninstall --non-composer` (app/code installs) calls only the data
 * patch reverts, so RemoveSettingsOnUninstall::revert() runs the same
 * removal. Disabling the module calls neither. The module's tables are
 * Magento's: declarative schema drops them when the module is disabled.
 *
 * The module is normally disabled when this runs (docs/INSTALLING.md), so
 * Magento builds this class without the module's di.xml: every constructor
 * dependency resolves from Magento's own configuration, and the engine
 * connection is read straight from the table, as the removal deletes it.
 */
class Uninstall implements UninstallInterface
{
    public function __construct(
        private readonly Disconnector $disconnector,
        private readonly EncryptorInterface $encryptor,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->endEngineConnection($setup->getConnection(), $setup->getTable('core_config_data'));
        $this->removeSettings(
            $setup->getConnection(),
            $setup->getTable('core_config_data'),
            $setup->getTable('flag')
        );
        $this->removeSetupNotice($setup->getConnection(), $setup->getTable(SetupNotice::TABLE));
    }

    /**
     * Tell Campaign Intelligence that this store's connection ends, with the
     * stored key, before removeSettings() deletes it. Nothing is sent when
     * the store is not connected; nothing here stops the removal.
     */
    public function endEngineConnection(AdapterInterface $connection, string $configTable): void
    {
        try {
            $stored = $connection->fetchPairs(
                $connection->select()
                    ->from($configTable, ['path', 'value'])
                    ->where('scope = ?', 'default')
                    ->where('scope_id = ?', 0)
                    ->where('path IN (?)', [
                        Settings::XML_PATH_CONNECTED,
                        Settings::XML_PATH_API_KEY,
                        Settings::XML_PATH_ENDPOINTS,
                        Settings::XML_PATH_BASE_URL,
                    ])
            );
            $encryptedKey = (string)($stored[Settings::XML_PATH_API_KEY] ?? '');
            if (($stored[Settings::XML_PATH_CONNECTED] ?? '') !== '1' || $encryptedKey === '') {
                return;
            }
            $endpoints = json_decode((string)($stored[Settings::XML_PATH_ENDPOINTS] ?? ''), true);
            $outcome = $this->disconnector->endConnection(
                $this->encryptor->decrypt($encryptedKey),
                is_array($endpoints) ? $endpoints : [],
                (string)($stored[Settings::XML_PATH_BASE_URL] ?? '')
            );
        } catch (\Exception) {
            $outcome = Disconnector::NOT_TOLD;
        }
        $this->logger->info('Smaily Connect removal: Campaign Intelligence disconnect', ['outcome' => $outcome]);
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

    /**
     * Delete the "ready to set up" admin notice, read or not. Without
     * Magento_AdminNotification there is no table and nothing to delete.
     */
    public function removeSetupNotice(AdapterInterface $connection, string $inboxTable): void
    {
        if ($connection->isTableExists($inboxTable)) {
            $connection->delete($inboxTable, ['url IN (?)' => SetupNotice::ALL_URLS]);
        }
    }
}
