<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Setup\Uninstall;

/**
 * The app/code uninstall hook. `bin/magento module:uninstall --non-composer`
 * does not call the module's Uninstall class; it only reverts the module's
 * data patches. Applying this patch changes nothing; reverting it removes
 * the module's settings, flag rows and setup notice exactly as Uninstall
 * does. On a
 * composer `module:uninstall` both run, and the second finds nothing left.
 */
class RemoveSettingsOnUninstall implements DataPatchInterface, PatchRevertableInterface
{
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Uninstall $uninstall
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function revert(): void
    {
        $this->uninstall->removeSettings(
            $this->moduleDataSetup->getConnection(),
            $this->moduleDataSetup->getTable('core_config_data'),
            $this->moduleDataSetup->getTable('flag')
        );
        $this->uninstall->removeSetupNotice(
            $this->moduleDataSetup->getConnection(),
            $this->moduleDataSetup->getTable(SetupNotice::TABLE)
        );
    }
}
