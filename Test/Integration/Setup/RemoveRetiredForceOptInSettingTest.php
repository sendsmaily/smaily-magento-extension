<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Setup;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Setup\Patch\Data\RemoveRetiredForceOptInSetting;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;

/**
 * PRO-3602: the update deletes the stored value of the retired
 * "Automations May Re-Subscribe (Advanced)" setting at every scope, and
 * touches no other setting.
 */
class RemoveRetiredForceOptInSettingTest extends IntegrationTestCase
{
    public function testApplyDeletesTheRetiredSettingAtEveryScopeAndNothingElse(): void
    {
        $rows = [
            ['default', 0, RemoveRetiredForceOptInSetting::RETIRED_PATH, '1'],
            ['websites', 1, RemoveRetiredForceOptInSetting::RETIRED_PATH, '0'],
            ['websites', 2, RemoveRetiredForceOptInSetting::RETIRED_PATH, '1'],
            ['stores', 3, RemoveRetiredForceOptInSetting::RETIRED_PATH, '1'],
            // Neighbours: the same group, a path sharing the prefix, a near match.
            ['default', 0, 'smaily_connect/subscribers/include_guests', '1'],
            ['websites', 2, 'smaily_connect/subscribers/sync_enabled', '1'],
            ['default', 0, 'smaily_connect/subscribers/automation_force_opt_in_note', 'keep'],
            ['default', 0, 'smaily_connect/subscribers/automationXforce_opt_in', 'keep'],
            ['default', 0, 'general/locale/code', 'en_US'],
        ];
        foreach ($rows as [$scope, $scopeId, $path, $value]) {
            $this->connection->insert('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ]);
        }

        $this->patch()->apply();

        $remaining = $this->connection->fetchAll(
            $this->connection->select()
                ->from('core_config_data', ['scope', 'scope_id', 'path', 'value'])
                ->order(['path', 'scope', 'scope_id'])
        );
        self::assertSame([
            ['scope' => 'default', 'scope_id' => '0', 'path' => 'general/locale/code', 'value' => 'en_US'],
            [
                'scope' => 'default',
                'scope_id' => '0',
                'path' => 'smaily_connect/subscribers/automation_force_opt_in_note',
                'value' => 'keep',
            ],
            [
                'scope' => 'default',
                'scope_id' => '0',
                'path' => 'smaily_connect/subscribers/automationXforce_opt_in',
                'value' => 'keep',
            ],
            [
                'scope' => 'default',
                'scope_id' => '0',
                'path' => 'smaily_connect/subscribers/include_guests',
                'value' => '1',
            ],
            [
                'scope' => 'websites',
                'scope_id' => '2',
                'path' => 'smaily_connect/subscribers/sync_enabled',
                'value' => '1',
            ],
        ], $remaining);
    }

    public function testApplyWithoutAStoredValueChangesNothing(): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => 'smaily_connect/subscribers/include_guests',
            'value' => '1',
        ]);

        $this->patch()->apply();

        self::assertSame(
            ['smaily_connect/subscribers/include_guests'],
            $this->connection->fetchCol($this->connection->select()->from('core_config_data', ['path']))
        );
    }

    private function patch(): RemoveRetiredForceOptInSetting
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);

        return $this->objectManager->create(RemoveRetiredForceOptInSetting::class, [
            'moduleDataSetup' => new DataSetup($resourceConnection),
        ]);
    }
}
