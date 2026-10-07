<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Migration;

/**
 * Whether the 2.8.x settings migration (Setup\Patch\Data\MigrateLegacyConfig)
 * moved settings in this setup run, for the patch that runs after it in the
 * same process (AddSetupNotice). One shared instance per process; nothing is
 * stored.
 */
class MigrationOutcome
{
    private bool $migrated = false;

    /**
     * Record that 2.8.x settings were migrated.
     */
    public function markMigrated(): void
    {
        $this->migrated = true;
    }

    /**
     * Whether 2.8.x settings were migrated in this setup run.
     */
    public function wasMigrated(): bool
    {
        return $this->migrated;
    }
}
