<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Setup;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Psr\Log\NullLogger;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Disconnector;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Setup\Patch\Data\RemoveSettingsOnUninstall;
use Smaily\Connect\Setup\Uninstall;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3581: uninstalling the module removes every Smaily setting and flag
 * row from the store database — the credentials and the engine key
 * included — through both of Magento's uninstall paths: the module's
 * Uninstall class (`module:uninstall`, composer installs) and the
 * revertable data patch (`module:uninstall --non-composer`, app/code
 * installs). Disabling runs neither, and the patch's apply() changes nothing.
 * PRO-3628: both paths also remove the "ready to set up" admin notice.
 * CON-220: both paths first tell Campaign Intelligence that the connection
 * ends (contract §16), with the stored key; whatever the engine answers,
 * the settings still go.
 */
class UninstallTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $schema = new SchemaInstaller($this->connection);
        $schema->createFlag();
        $schema->createAdminNotificationInbox();
        $this->seedStoreData();
    }

    protected function tearDown(): void
    {
        $this->connection->query('DROP TABLE IF EXISTS `flag`');
        $this->connection->query('DROP TABLE IF EXISTS `adminnotification_inbox`');
        parent::tearDown();
    }

    public function testUninstallClassRemovesEverySmailySettingAndFlag(): void
    {
        $this->uninstall($this->notCalledDisconnector())
            ->uninstall($this->schemaSetup(), $this->createMock(ModuleContextInterface::class));

        $this->assertOnlyForeignRowsRemain();
    }

    /**
     * @dataProvider engineAnswers
     */
    public function testBothPathsTellTheEngineBeforeTheKeyIsDeletedWhateverItAnswers(
        string $path,
        string $outcome
    ): void {
        $this->connectEngine();
        $calls = [];
        $disconnector = $this->createMock(Disconnector::class);
        $disconnector->expects(self::once())->method('endConnection')->willReturnCallback(
            function (mixed ...$args) use (&$calls, $outcome): string {
                $calls[] = ['args' => $args, 'paths' => $this->configPaths()];

                return $outcome;
            }
        );
        $uninstall = $this->uninstall($disconnector);

        if ($path === 'uninstall') {
            $uninstall->uninstall($this->schemaSetup(), $this->createMock(ModuleContextInterface::class));
        } else {
            $this->patch($uninstall)->revert();
        }

        self::assertSame(
            [
                'sk_live_key',
                ['store_uninstall' => 'https://intelligence.smaily.com/api/v1/store/uninstall'],
                'https://intelligence.smaily.com',
            ],
            $calls[0]['args']
        );
        self::assertContains(EngineSettings::XML_PATH_API_KEY, $calls[0]['paths'], 'Told while the key is stored.');
        $this->assertOnlyForeignRowsRemain();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function engineAnswers(): array
    {
        $answers = [];
        foreach (['uninstall', 'patch revert'] as $path) {
            foreach ([Disconnector::PAUSED, Disconnector::KEY_REFUSED, Disconnector::NOT_TOLD] as $outcome) {
                $answers[$path . ', ' . $outcome] = [$path, $outcome];
            }
        }

        return $answers;
    }

    public function testAFailureWhileTellingTheEngineDoesNotStopTheRemoval(): void
    {
        $this->connectEngine();
        $disconnector = $this->createMock(Disconnector::class);
        $disconnector->method('endConnection')->willThrowException(new \RuntimeException('unexpected'));

        $this->uninstall($disconnector)
            ->uninstall($this->schemaSetup(), $this->createMock(ModuleContextInterface::class));

        $this->assertOnlyForeignRowsRemain();
    }

    public function testRevertingTheDataPatchRemovesEverySmailySettingAndFlag(): void
    {
        $this->patch()->revert();

        $this->assertOnlyForeignRowsRemain();
    }

    public function testApplyingTheDataPatchKeepsEverything(): void
    {
        $configBefore = $this->configPaths();
        $flagsBefore = $this->flagCodes();
        $noticesBefore = $this->noticeTitles();

        $this->patch()->apply();

        self::assertSame($configBefore, $this->configPaths());
        self::assertSame($flagsBefore, $this->flagCodes());
        self::assertSame($noticesBefore, $this->noticeTitles());
    }

    /**
     * Magento_AdminNotification may be disabled: there is no notice to
     * remove, and the settings still go.
     */
    public function testWithoutTheNotificationTableBothPathsStillRemoveTheSettings(): void
    {
        $this->connection->query('DROP TABLE `adminnotification_inbox`');

        $this->uninstall($this->notCalledDisconnector())
            ->uninstall($this->schemaSetup(), $this->createMock(ModuleContextInterface::class));
        $this->patch()->revert();

        self::assertSame(['general/locale/code', 'smailyXconnect/foo/bar'], $this->configPaths());
    }

    /**
     * Without an Uninstall, the object manager builds one as Magento does,
     * with the real Disconnector; the seeded store is not connected, so it
     * sends nothing.
     */
    private function patch(?Uninstall $uninstall = null): RemoveSettingsOnUninstall
    {
        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $arguments = ['moduleDataSetup' => new DataSetup($resourceConnection)];
        if ($uninstall !== null) {
            $arguments['uninstall'] = $uninstall;
        }

        return $this->objectManager->create(RemoveSettingsOnUninstall::class, $arguments);
    }

    private function uninstall(Disconnector $disconnector): Uninstall
    {
        return new Uninstall($disconnector, $this->objectManager->get(EncryptorInterface::class), new NullLogger());
    }

    private function notCalledDisconnector(): Disconnector
    {
        $disconnector = $this->createMock(Disconnector::class);
        $disconnector->expects(self::never())->method('endConnection');

        return $disconnector;
    }

    private function schemaSetup(): SchemaSetupInterface
    {
        $setup = $this->createMock(SchemaSetupInterface::class);
        $setup->method('getConnection')->willReturn($this->connection);
        $setup->method('getTable')->willReturnArgument(0);

        return $setup;
    }

    /**
     * A connected store: the key encrypted as Settings stores it.
     */
    private function connectEngine(): void
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $this->connection->delete('core_config_data', ['path = ?' => EngineSettings::XML_PATH_API_KEY]);
        foreach ([
            EngineSettings::XML_PATH_CONNECTED => '1',
            EngineSettings::XML_PATH_API_KEY => $encryptor->encrypt('sk_live_key'),
            EngineSettings::XML_PATH_BASE_URL => 'https://intelligence.smaily.com',
            EngineSettings::XML_PATH_ENDPOINTS
                => '{"store_uninstall":"https://intelligence.smaily.com/api/v1/store/uninstall"}',
        ] as $path => $value) {
            $this->connection->insert('core_config_data', [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => $path,
                'value' => $value,
            ]);
        }
    }

    private function seedStoreData(): void
    {
        $config = [
            ['default', 0, Config::XML_PATH_PASSWORD, '0:3:encrypted-password'],
            ['websites', 2, Config::XML_PATH_PASSWORD, '0:3:encrypted-password-2'],
            ['default', 0, Config::XML_PATH_USERNAME, 'api-user'],
            ['default', 0, EngineSettings::XML_PATH_API_KEY, '0:3:encrypted-engine-key'],
            ['default', 0, EngineSettings::XML_PATH_TENANT_ID, 'tenant-1'],
            ['stores', 3, 'smaily_connect/subscribers/sync_enabled', '1'],
            // 2.8.x leftovers, the plaintext password among them.
            ['default', 0, 'smaily/general/password', 'plain-secret'],
            ['websites', 2, 'smaily/general/username', 'legacy-user'],
            // Not ours: an underscore in a LIKE pattern matches any character.
            ['default', 0, 'smailyXconnect/foo/bar', 'keep'],
            ['default', 0, 'general/locale/code', 'en_US'],
        ];
        foreach ($config as [$scope, $scopeId, $path, $value]) {
            $this->connection->insert('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ]);
        }

        $flags = [
            ProfilingOptOuts::FLAG_CODE,
            VerifiedCredentials::FLAG_CODE,
            OrderOrigin::FLAG_LAST_STOREFRONT_ORDER,
            OrderOrigin::FLAG_LAST_API_ORDER,
            'smaily_connect_engine_down_since',
            'smaily_connect_reconcile_seq_w1',
            'smailyXconnect_foo',
            'catalog_website_attribute_is_sync_required',
        ];
        foreach ($flags as $code) {
            $this->connection->insert('flag', ['flag_code' => $code, 'flag_data' => '{}']);
        }

        $notices = [
            ['Smaily Connect is ready to set up', SetupNotice::URL],
            // The link a release candidate wrote (SetupNotice::PREVIOUS_URLS).
            ['Smaily Connect is ready to set up', SetupNotice::PREVIOUS_URLS[0]],
            ['Smaily Connect upgrade', null],
            ['Magento security update', 'https://example.com/security'],
        ];
        foreach ($notices as [$title, $url]) {
            $this->connection->insert('adminnotification_inbox', ['title' => $title, 'url' => $url]);
        }
    }

    private function assertOnlyForeignRowsRemain(): void
    {
        self::assertSame(['general/locale/code', 'smailyXconnect/foo/bar'], $this->configPaths());
        self::assertSame(['catalog_website_attribute_is_sync_required', 'smailyXconnect_foo'], $this->flagCodes());
        self::assertSame(['Magento security update', 'Smaily Connect upgrade'], $this->noticeTitles());
    }

    /**
     * @return string[]
     */
    private function noticeTitles(): array
    {
        return $this->connection->fetchCol(
            $this->connection->select()->from('adminnotification_inbox', ['title'])->order('title')
        );
    }

    /**
     * @return string[]
     */
    private function configPaths(): array
    {
        return $this->connection->fetchCol(
            $this->connection->select()->from('core_config_data', ['path'])->order(['path', 'scope', 'scope_id'])
        );
    }

    /**
     * @return string[]
     */
    private function flagCodes(): array
    {
        return $this->connection->fetchCol(
            $this->connection->select()->from('flag', ['flag_code'])->order('flag_code')
        );
    }
}
