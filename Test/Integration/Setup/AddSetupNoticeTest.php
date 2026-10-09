<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Migration\MigrationOutcome;
use Smaily\Connect\Setup\Patch\Data\AddSetupNotice;
use Smaily\Connect\Setup\Patch\Data\MigrateLegacyConfig;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;

/**
 * PRO-3628: the "ready to set up" notice says that earlier settings were
 * migrated only when the 2.8.x migration moved settings in the same setup
 * run — not on a fresh install.
 */
class AddSetupNoticeTest extends IntegrationTestCase
{
    private const MIGRATED = 'Existing settings from an earlier version were migrated automatically.';

    public function testAFreshInstallNoticeDoesNotSayAnythingWasMigrated(): void
    {
        $this->runSetupPatches();

        self::assertSame(
            'Open Marketing > Smaily Connect > Initial setup to connect your Smaily account in a few guided steps.'
            . ' The full user guide is linked below under Read Details.',
            $this->setupNoticeDescription()
        );
    }

    public function testAnUpgradeFromTwoEightNoticeSaysTheSettingsWereMigrated(): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => 'smaily/general/username',
            'value' => 'legacy-user',
        ]);

        $this->runSetupPatches();

        self::assertStringContainsString(self::MIGRATED, $this->setupNoticeDescription());
    }

    /**
     * The two patches in dependency order, sharing one outcome as the
     * object manager's shared instance does in a real setup run.
     */
    private function runSetupPatches(): void
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $outcome = new MigrationOutcome();

        $this->objectManager->create(MigrateLegacyConfig::class, [
            'moduleDataSetup' => new DataSetup($resourceConnection),
            'migrationOutcome' => $outcome,
            'storeManager' => $this->createConfiguredMock(StoreManagerInterface::class, ['getWebsites' => []]),
        ])->apply();
        $this->objectManager->create(AddSetupNotice::class, ['migrationOutcome' => $outcome])->apply();
    }

    private function setupNoticeDescription(): string
    {
        foreach ($this->env->getNotifier()->getNotifications() as $notification) {
            if ($notification['title'] === 'Smaily Connect is ready to set up') {
                return $notification['description'];
            }
        }
        self::fail('No "ready to set up" notice was added');
    }
}
