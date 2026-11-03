<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Setup\Patch\Data;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Setup\Patch\Data\RecordEngineConnectionAccount;

/**
 * CON-237: a connection made before it recorded its Smaily account gets
 * the account it was made for; nothing else is written.
 */
class RecordEngineConnectionAccountTest extends TestCase
{
    public function testAConnectionWithoutItsAccountGetsTheInstallationsAccount(): void
    {
        $settings = $this->settings(true, '');
        $settings->expects(self::once())->method('recordSmailySubdomain')->with('shop');

        $this->patch($settings, 'shop')->apply();
    }

    public function testAStoreThatIsNotConnectedIsLeftAsItIs(): void
    {
        $settings = $this->settings(false, '');
        $settings->expects(self::never())->method('recordSmailySubdomain');

        $this->patch($settings, 'shop')->apply();
    }

    public function testAConnectionThatNamesItsAccountIsLeftAsItIs(): void
    {
        $settings = $this->settings(true, 'shop-lv');
        $settings->expects(self::never())->method('recordSmailySubdomain');

        $this->patch($settings, 'shop')->apply();
    }

    public function testNoAccountSavedWritesNothing(): void
    {
        $settings = $this->settings(true, '');
        $settings->expects(self::never())->method('recordSmailySubdomain');

        $this->patch($settings, '')->apply();
    }

    public function testItRunsAfterTheMigrationAndHasNoAliases(): void
    {
        self::assertNotSame([], RecordEngineConnectionAccount::getDependencies());
        self::assertSame([], $this->patch($this->settings(false, ''), '')->getAliases());
    }

    private function settings(bool $connected, string $account): Settings&MockObject
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($connected);
        $settings->method('getSmailySubdomain')->willReturn($account);

        return $settings;
    }

    private function patch(Settings $settings, string $installationAccount): RecordEngineConnectionAccount
    {
        $connections = $this->createMock(Connections::class);
        $connections->method('installationAccount')->willReturn($installationAccount);

        return new RecordEngineConnectionAccount($settings, $connections);
    }
}
