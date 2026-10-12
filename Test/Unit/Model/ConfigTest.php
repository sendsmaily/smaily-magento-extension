<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;

/**
 * PRO-4036: a website's contact sync is active only while its sync switch
 * is on AND its Contacts settings were saved — the default sync switch of a
 * new install alone sends nothing.
 */
class ConfigTest extends TestCase
{
    /**
     * @dataProvider activeProvider
     */
    public function testContactSyncIsActiveOnlyWithTheSwitchOnAndTheContactsSettingsSaved(
        bool $syncEnabled,
        bool $contactsSaved,
        bool $active
    ): void {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnMap([
            [Config::XML_PATH_SYNC_ENABLED, ScopeInterface::SCOPE_WEBSITE, 3, $syncEnabled],
            [Config::XML_PATH_CONTACTS_CONFIRMED, ScopeInterface::SCOPE_WEBSITE, 3, $contactsSaved],
        ]);

        $config = new Config($scopeConfig, $this->createMock(EncryptorInterface::class));

        self::assertSame($active, $config->isContactSyncActive(3));
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function activeProvider(): array
    {
        return [
            'a new install: sync on by default, Contacts not saved' => [true, false, false],
            'Contacts saved with sync on' => [true, true, true],
            'Contacts saved with sync off' => [false, true, false],
            'neither' => [false, false, false],
        ];
    }
}
