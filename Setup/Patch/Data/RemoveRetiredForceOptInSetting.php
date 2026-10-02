<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Deletes the stored value of the retired "Automations May Re-Subscribe
 * (Advanced)" setting at every scope, as the WooCommerce plugin did in
 * 3.11.1. Nothing has read it since automations always send
 * force_opt_in=false; this patch removes the row itself. Only that exact
 * path is touched.
 */
class RemoveRetiredForceOptInSetting implements DataPatchInterface
{
    public const RETIRED_PATH = 'smaily_connect/subscribers/automation_force_opt_in';

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
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
        $this->moduleDataSetup->getConnection()->delete(
            $this->moduleDataSetup->getTable('core_config_data'),
            ['path = ?' => self::RETIRED_PATH]
        );

        return $this;
    }
}
