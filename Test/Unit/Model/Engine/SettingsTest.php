<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Settings;

/**
 * The remembered refusal (contract §2 `403 tenant_inactive`, PRO-2451): the
 * state every send path is gated on. The scope of a connection (CON-237):
 * the primary's default scope, written as before, and a website's own,
 * which never reads the default scope's values.
 */
class SettingsTest extends TestCase
{
    /** @var array<string, string> core_config_data at the default scope */
    private array $values = [];

    /** @var array<string, array<int, array<string, string>>> scope => scope id => path => value */
    private array $rows = [];

    /** @var array<int, array{0: string, 1: string, 2: string|null, 3: string, 4: int}> writer calls */
    private array $calls = [];

    public function testTheFirstRefusalTimestampIsKept(): void
    {
        $settings = $this->createSettings();

        $settings->recordRefusal();
        $settings->recordRefusal();

        self::assertTrue($settings->isRefused());
        // The merchant wants to know when sending stopped, not when it was
        // last attempted, so the second refusal must not move the record.
        self::assertSame('2026-09-10 09:00:00', $settings->getRefusedAt());
    }

    public function testARefusedAccountMayNotSendButStaysConnected(): void
    {
        $settings = $this->createSettings();
        self::assertTrue($settings->isSendingAllowed());

        $settings->recordRefusal();

        self::assertFalse($settings->isSendingAllowed());
        self::assertTrue($settings->isConnected(), 'Nothing about the connection is wrong, only the account');
    }

    public function testClearingTheRefusalLetsSendingResume(): void
    {
        $settings = $this->createSettings();
        $settings->recordRefusal();

        $settings->clearRefusal();

        self::assertFalse($settings->isRefused());
        self::assertTrue($settings->isSendingAllowed());
    }

    public function testAQueueHandlerIsToldWhySendingIsBlocked(): void
    {
        $settings = $this->createSettings();
        self::assertNull($settings->sendingBlockedReason());

        $settings->recordRefusal();

        self::assertSame('Campaign Intelligence account is not active', $settings->sendingBlockedReason());
    }

    /**
     * CON-258: the pushed workflows belong to the connection that read
     * them, so a new setup exchange and a disconnect delete them.
     */
    public function testANewConnectionAndADisconnectDeleteThePushedWorkflows(): void
    {
        $settings = $this->createSettings();
        $this->values[Settings::XML_PATH_EMAIL_WORKFLOWS] = '{"plugin.welcome":{"workflow_id":456,"subdomain":"shop"}}';

        $settings->storeExchange(['api_key' => 'sk_live_new', 'tenant_id' => 't_2']);

        self::assertArrayNotHasKey(Settings::XML_PATH_EMAIL_WORKFLOWS, $this->values);

        $this->values[Settings::XML_PATH_EMAIL_WORKFLOWS] = '{"plugin.welcome":{"workflow_id":456,"subdomain":"shop"}}';
        $settings->clear();

        self::assertArrayNotHasKey(Settings::XML_PATH_EMAIL_WORKFLOWS, $this->values);
    }

    /**
     * CON-237: the primary's settings write and delete exactly the rows
     * they did before connections had scopes — the default scope, the same
     * calls in the same order — and record the connection's account.
     */
    public function testThePrimaryWritesTheDefaultScopeExactlyAsBefore(): void
    {
        $settings = $this->createScopedSettings();
        $this->rows['default'][0][Settings::XML_PATH_REFUSED_AT] = '2026-09-01 08:00:00';

        $settings->storeExchange([
            'api_key' => 'sk_live_new',
            'tenant_id' => 't_1',
            'tenant_name' => 'Shop',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'engine_version' => '1.25.0',
            'endpoints' => ['catalog' => 'https://intelligence.smaily.com/api/v1/catalog'],
            'config' => ['batch_size_max' => 100],
            'issued_at' => '2026-11-03T10:00:00Z',
        ], 'shop');
        $settings->clear();

        $default = static fn (string $call, string $path, ?string $value = null): array
            => [$call, $path, $value, 'default', 0];
        self::assertSame([
            $default('save', Settings::XML_PATH_API_KEY, 'encrypted'),
            $default('save', Settings::XML_PATH_TENANT_ID, 't_1'),
            $default('save', Settings::XML_PATH_TENANT_NAME, 'Shop'),
            $default('save', Settings::XML_PATH_BASE_URL, 'https://intelligence.smaily.com'),
            $default('save', Settings::XML_PATH_ENGINE_VERSION, '1.25.0'),
            $default('save', Settings::XML_PATH_ENDPOINTS, '{"catalog":"https:\/\/intelligence.smaily.com\/api\/v1\/catalog"}'),
            $default('save', Settings::XML_PATH_CONFIG, '{"batch_size_max":100}'),
            $default('save', Settings::XML_PATH_ISSUED_AT, '2026-11-03T10:00:00Z'),
            $default('save', Settings::XML_PATH_CONNECTED, '1'),
            $default('delete', Settings::XML_PATH_EMAIL_WORKFLOWS),
            $default('save', Settings::XML_PATH_SMAILY_SUBDOMAIN, 'shop'),
            $default('delete', Settings::XML_PATH_REFUSED_AT),
            $default('delete', Settings::XML_PATH_CONNECTED),
            $default('delete', Settings::XML_PATH_TENANT_ID),
            $default('delete', Settings::XML_PATH_TENANT_NAME),
            $default('delete', Settings::XML_PATH_API_KEY),
            $default('delete', Settings::XML_PATH_BASE_URL),
            $default('delete', Settings::XML_PATH_ENGINE_VERSION),
            $default('delete', Settings::XML_PATH_ENDPOINTS),
            $default('delete', Settings::XML_PATH_CONFIG),
            $default('delete', Settings::XML_PATH_ISSUED_AT),
            $default('delete', Settings::XML_PATH_REFUSED_AT),
            $default('delete', Settings::XML_PATH_EMAIL_WORKFLOWS),
            $default('delete', Settings::XML_PATH_SMAILY_SUBDOMAIN),
        ], $this->calls);
        self::assertSame(
            [Settings::XML_PATH_BROWSE_TRACKING => '1'],
            $this->rows['default'][0],
            'A disconnect keeps the browse tracking choice'
        );
        self::assertNull($settings->getWebsiteId());
        self::assertFalse($settings->isWebsiteConnection());
    }

    public function testThePrimaryRecordsItsRefusalAtTheDefaultScope(): void
    {
        $settings = $this->createScopedSettings();

        $settings->recordRefusal();
        $settings->clearRefusal();

        self::assertSame([
            ['save', Settings::XML_PATH_REFUSED_AT, '2026-09-10 09:00:00', 'default', 0],
            ['delete', Settings::XML_PATH_REFUSED_AT, null, 'default', 0],
        ], $this->calls);
    }

    /**
     * The account the engine names (contract v1.25.0 §1) wins over the
     * connecting one; an answer without it records the connecting one.
     */
    public function testTheConnectionRecordsTheAccountTheEngineNamesElseTheConnectingOne(): void
    {
        $settings = $this->createScopedSettings();

        $settings->storeExchange(['api_key' => 'k', 'smaily_subdomain' => ' Shop-LV '], 'shop');
        self::assertSame('shop-lv', $settings->getSmailySubdomain());

        $settings->storeExchange(['api_key' => 'k', 'smaily_subdomain' => null], 'shop');
        self::assertSame('shop', $settings->getSmailySubdomain());
    }

    /**
     * A website's own connection holds every value of its own: it never
     * reads the default scope's refusal, pushed workflows or browse
     * tracking, nor is it connected before its own exchange.
     */
    public function testAWebsiteConnectionNeverReadsTheDefaultScopesValues(): void
    {
        $primary = $this->createScopedSettings();
        $this->rows['default'][0] += [
            Settings::XML_PATH_CONNECTED => '1',
            Settings::XML_PATH_API_KEY => 'encrypted',
            Settings::XML_PATH_TENANT_ID => 't_1',
            Settings::XML_PATH_REFUSED_AT => '2026-09-01 08:00:00',
            Settings::XML_PATH_EMAIL_WORKFLOWS => '{"plugin.welcome":{"workflow_id":456,"subdomain":"shop"}}',
        ];
        $website = $primary->withWebsite(2);

        self::assertSame(2, $website->getWebsiteId());
        self::assertFalse($website->isWebsiteConnection());
        self::assertFalse($website->isConnected(), 'The default scope\'s connection is not the website\'s');

        $website->storeExchange(['api_key' => 'sk_live_lv', 'tenant_id' => 't_2'], 'shop-lv');

        self::assertTrue($website->isWebsiteConnection());
        self::assertTrue($website->isConnected());
        self::assertFalse($website->isRefused());
        self::assertSame('', $website->getRefusedAt());
        self::assertFalse($website->isBrowseTrackingEnabled());
        self::assertSame('t_2', $website->getTenantId());
        self::assertSame('shop-lv', $website->getSmailySubdomain());
        foreach (Settings::WEBSITE_PATHS as $path) {
            self::assertArrayHasKey($path, $this->rows['websites'][2], 'Its own value of ' . $path);
        }
        self::assertSame('', $this->rows['websites'][2][Settings::XML_PATH_EMAIL_WORKFLOWS]);
        foreach ($this->calls as [$call, , , $scope, $scopeId]) {
            self::assertSame(['save', 'websites', 2], [$call, $scope, $scopeId], 'Never a single delete');
        }
        // The primary is as it was.
        self::assertSame('t_1', $primary->getTenantId());
        self::assertTrue($primary->isRefused());
        self::assertSame('2026-09-01 08:00:00', $this->rows['default'][0][Settings::XML_PATH_REFUSED_AT]);
    }

    public function testAWebsiteConnectionKeepsItsBrowseTrackingChoiceOnAReconnect(): void
    {
        $website = $this->createScopedSettings()->withWebsite(2);
        $website->storeExchange(['api_key' => 'k', 'tenant_id' => 't_2'], 'shop-lv');
        $this->rows['websites'][2][Settings::XML_PATH_BROWSE_TRACKING] = '1';

        $website->storeExchange(['api_key' => 'k', 'tenant_id' => 't_2'], 'shop-lv');

        self::assertTrue($website->isBrowseTrackingEnabled());
    }

    public function testAWebsiteConnectionsRefusalIsItsOwn(): void
    {
        $primary = $this->createScopedSettings();
        $website = $primary->withWebsite(2);
        $website->storeExchange(['api_key' => 'k', 'tenant_id' => 't_2'], 'shop-lv');

        $website->recordRefusal();

        self::assertTrue($website->isRefused());
        self::assertFalse($primary->isRefused());
        self::assertArrayNotHasKey(Settings::XML_PATH_REFUSED_AT, $this->rows['default'][0]);

        $website->clearRefusal();

        self::assertFalse($website->isRefused());
        self::assertSame('', $this->rows['websites'][2][Settings::XML_PATH_REFUSED_AT], 'Written empty, not deleted');
        self::assertFalse($website->withWebsite(2)->isRefused(), 'A fresh copy reads it back');
    }

    public function testACopyBoundToAWebsiteLeavesTheOriginalAndItsMemosAlone(): void
    {
        $primary = $this->createScopedSettings();
        $primary->recordRefusal();
        self::assertTrue($primary->isRefused());

        $website = $primary->withWebsite(3);

        self::assertNotSame($primary, $website);
        self::assertNull($primary->getWebsiteId());
        self::assertSame(3, $website->getWebsiteId());
        self::assertSame(4, $website->withWebsite(4)->getWebsiteId());
        self::assertSame(3, $website->getWebsiteId());

        $this->expectException(\InvalidArgumentException::class);
        $primary->withWebsite(0);
    }

    public function testClearingRemovesEveryRowOfItsOwnScopeOnly(): void
    {
        $primary = $this->createScopedSettings();
        $primary->storeExchange(['api_key' => 'k', 'tenant_id' => 't_1'], 'shop');
        $latvia = $primary->withWebsite(2);
        $latvia->storeExchange(['api_key' => 'k', 'tenant_id' => 't_2'], 'shop-lv');
        $finland = $primary->withWebsite(3);
        $finland->storeExchange(['api_key' => 'k', 'tenant_id' => 't_3'], 'shop-fi');

        $latvia->clear();

        self::assertArrayNotHasKey(2, $this->rows['websites']);
        self::assertFalse($latvia->isConnected());
        self::assertTrue($finland->isConnected());
        self::assertTrue($primary->isConnected());
        self::assertSame('t_1', $primary->getTenantId());

        $primary->clear();

        self::assertFalse($primary->isConnected());
        self::assertTrue($finland->isConnected());
        self::assertSame('t_3', $finland->getTenantId());
    }

    /**
     * Settings over core_config_data by scope, with Magento's website ->
     * default fallback; every writer call is recorded.
     */
    private function createScopedSettings(): Settings
    {
        $this->rows = ['default' => [0 => [Settings::XML_PATH_BROWSE_TRACKING => '1']], 'websites' => []];
        $this->calls = [];

        $read = function (string $path, string $scope = 'default', mixed $code = null): ?string {
            if ($scope === 'website' || $scope === 'websites') {
                $value = $this->rows['websites'][(int)$code][$path] ?? null;
                if ($value !== null) {
                    return $value;
                }
            }

            return $this->rows['default'][0][$path] ?? null;
        };
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback($read);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope = 'default', mixed $code = null): bool
                => (bool)$read($path, $scope, $code)
        );

        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            function (string $path, string $value, string $scope = 'default', int $scopeId = 0): void {
                $this->calls[] = ['save', $path, $value, $scope, $scopeId];
                $this->rows[$scope][$scopeId][$path] = $value;
            }
        );
        $writer->method('delete')->willReturnCallback(
            function (string $path, string $scope = 'default', int $scopeId = 0): void {
                $this->calls[] = ['delete', $path, null, $scope, $scopeId];
                unset($this->rows[$scope][$scopeId][$path]);
                if (($this->rows[$scope][$scopeId] ?? null) === [] && $scope !== 'default') {
                    unset($this->rows[$scope][$scopeId]);
                }
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('sk_live_key');
        $encryptor->method('encrypt')->willReturn('encrypted');

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn('2026-09-10 09:00:00');

        return new Settings(
            $scopeConfig,
            $writer,
            $encryptor,
            $this->createMock(TypeListInterface::class),
            new Json(),
            $dateTime
        );
    }

    private function createSettings(): Settings
    {
        $this->values = [Settings::XML_PATH_API_KEY => 'encrypted'];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(true);
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): ?string => $this->values[$path] ?? null
        );

        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(
            function (string $path, string $value): void {
                $this->values[$path] = $value;
            }
        );
        $writer->method('delete')->willReturnCallback(
            function (string $path): void {
                unset($this->values[$path]);
            }
        );

        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('sk_live_key');
        $encryptor->method('encrypt')->willReturn('encrypted');

        // A second refusal one day later must not overwrite the first.
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')
            ->willReturnOnConsecutiveCalls('2026-09-10 09:00:00', '2026-09-11 10:00:00');

        return new Settings(
            $scopeConfig,
            $writer,
            $encryptor,
            $this->createMock(TypeListInterface::class),
            new Json(),
            $dateTime
        );
    }
}
