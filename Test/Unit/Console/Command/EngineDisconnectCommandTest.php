<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Console\Command;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Console\Command\EngineDisconnectCommand;
use Smaily\Connect\Model\Engine\Disconnector;
use Smaily\Connect\Model\Engine\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CON-220: Disconnect tells the engine that this connection ends (contract
 * v1.18.0 §16), with the stored key and before the key is deleted, then
 * removes the local connection whatever the engine answered. The engine
 * pauses a store with no connection left and keeps its data; nothing tells
 * the merchant that the store's data is deleted. Replaces CON-143's "no
 * request to the engine" (Erkki's decision 2026-10-23, WooCommerce CON-219).
 */
class EngineDisconnectCommandTest extends TestCase
{
    private const ENDPOINTS = '{"store_uninstall":"https://intelligence.smaily.com/api/v1/store/uninstall"}';

    /** @var array<string, string> */
    private array $values = [];

    /** @var array<int, array{args: array<int, mixed>, stored: array<string, string>}> */
    private array $calls = [];

    /**
     * @dataProvider outcomes
     */
    public function testTheEngineIsToldBeforeTheKeyIsDeletedAndTheKeyGoesWhateverItAnswers(
        string $outcome,
        string $note
    ): void {
        $tester = $this->runCommand($outcome, ['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->calls);
        self::assertSame(
            ['sk_live_key', json_decode(self::ENDPOINTS, true), 'https://intelligence.smaily.com'],
            $this->calls[0]['args']
        );
        self::assertArrayHasKey(
            Settings::XML_PATH_API_KEY,
            $this->calls[0]['stored'],
            'The engine is told while the key is still stored.'
        );
        self::assertSame([], $this->values, 'Disconnect removes the stored engine key and connection.');

        $display = $tester->getDisplay();
        self::assertStringContainsString($note, $display);
        self::assertSame(1, substr_count(trim($display), "\n") + 1, 'One line.');
        self::assertStringNotContainsStringIgnoringCase('delet', $display);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function outcomes(): array
    {
        return [
            'store paused' => [Disconnector::PAUSED, 'Campaign Intelligence paused the store'],
            'another connection remains' => [Disconnector::CONNECTION_REMOVED, 'other connection keeps working'],
            'another 200' => [Disconnector::ENDED, 'Campaign Intelligence ended this connection.'],
            'key refused (401)' => [Disconnector::KEY_REFUSED, 'the connection had already ended there'],
            'engine not reached' => [Disconnector::NOT_TOLD, 'could not be told'],
        ];
    }

    /**
     * A suspended account (`403 tenant_inactive` remembered) may still
     * disconnect: §16 does not refuse it, so it is told once.
     */
    public function testARefusedAccountIsStillToldOnce(): void
    {
        $tester = $this->runCommand(Disconnector::PAUSED, ['--force' => true], refused: true);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->calls);
        self::assertSame([], $this->values);
    }

    public function testWithoutForceNothingIsSentAndTheKeyStays(): void
    {
        $tester = $this->runCommand(Disconnector::PAUSED, []);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame([], $this->calls);
        self::assertArrayHasKey(Settings::XML_PATH_API_KEY, $this->values);
    }

    public function testANotConnectedStoreSendsNothing(): void
    {
        $tester = $this->runCommand(Disconnector::PAUSED, ['--force' => true], connected: false);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $this->calls);
        self::assertStringContainsString('not connected', $tester->getDisplay());
    }

    /**
     * @param array<string, bool> $input
     */
    private function runCommand(
        string $outcome,
        array $input,
        bool $connected = true,
        bool $refused = false
    ): CommandTester {
        $this->values = [
            Settings::XML_PATH_CONNECTED => $connected ? '1' : '',
            Settings::XML_PATH_API_KEY => 'encrypted',
            Settings::XML_PATH_BASE_URL => 'https://intelligence.smaily.com',
            Settings::XML_PATH_ENDPOINTS => self::ENDPOINTS,
        ];
        if ($refused) {
            $this->values[Settings::XML_PATH_REFUSED_AT] = '2026-10-23 10:00:00';
        }
        $this->calls = [];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            fn (string $path): bool => ($this->values[$path] ?? '') === '1'
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            fn (string $path): ?string => $this->values[$path] ?? null
        );
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('delete')->willReturnCallback(function (string $path): void {
            unset($this->values[$path]);
        });
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->with('encrypted')->willReturn('sk_live_key');
        $settings = new Settings(
            $scopeConfig,
            $writer,
            $encryptor,
            $this->createMock(TypeListInterface::class),
            new Json(),
            $this->createMock(DateTime::class)
        );

        $disconnector = $this->createMock(Disconnector::class);
        $disconnector->method('endConnection')->willReturnCallback(
            function (mixed ...$args) use ($outcome): string {
                $this->calls[] = ['args' => $args, 'stored' => $this->values];

                return $outcome;
            }
        );

        $tester = new CommandTester(new EngineDisconnectCommand($settings, $disconnector));
        $tester->execute($input);

        return $tester;
    }
}
