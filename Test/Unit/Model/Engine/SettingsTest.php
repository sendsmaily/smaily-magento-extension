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
 * state every send path is gated on.
 */
class SettingsTest extends TestCase
{
    /** @var array<string, string> core_config_data at the default scope */
    private array $values = [];

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
