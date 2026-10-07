<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Migration\MigrationOutcome;

/**
 * Points the merchant at the setup wizard after installation/upgrade. It
 * says that earlier settings were migrated only when MigrateLegacyConfig
 * moved 2.8.x settings in the same setup run.
 * Finishing the setup marks the notice read; uninstalling removes it
 * (Model\Adminhtml\SetupNotice).
 */
class AddSetupNotice implements DataPatchInterface
{
    public function __construct(
        private readonly NotifierInterface $notifier,
        private readonly MigrationOutcome $migrationOutcome
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [MigrateLegacyConfig::class];
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
        $this->notifier->addNotice(
            (string)__('Smaily Connect is ready to set up'),
            $this->migrationOutcome->wasMigrated()
                ? (string)__(
                    'Open Marketing > Smaily Connect > Initial setup to connect your Smaily account'
                    . ' in a few guided steps. Existing settings from an earlier version were migrated automatically.'
                    . ' The full user guide is linked below under Read Details.'
                )
                : (string)__(
                    'Open Marketing > Smaily Connect > Initial setup to connect your Smaily account'
                    . ' in a few guided steps. The full user guide is linked below under Read Details.'
                ),
            SetupNotice::URL
        );

        return $this;
    }
}
