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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;
use Smaily\Connect\Console\Command\EngineDisconnectCommand;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Setup\Patch\Data\RemoveSettingsOnUninstall;
use Smaily\Connect\Setup\Uninstall;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The extension never asks the engine to delete the store's data (CON-143).
 *
 * Contract §16 (`POST /api/v1/store/uninstall`) deletes ALL of a store's
 * engine data when the store's last connection calls it. Erkki's decision
 * (2026-10-22, with the WooCommerce plugin's CON-51): the extension never
 * sends it — not on removal, not on disconnect. Both only remove the
 * extension's own stored engine key; the store's data is deleted when the
 * merchant closes the Smaily account (a merchant who removes the extension
 * may only be changing platform).
 */
class EngineDisconnectCommandTest extends TestCase
{
    /** Namespaces whose classes can send a request; none may reach a disconnect. */
    private const HTTP_NAMESPACES = [
        'Smaily\\Connect\\Model\\Engine\\Client',
        'GuzzleHttp\\',
        'Psr\\Http\\',
        'Magento\\Framework\\HTTP\\',
    ];

    /** The directories whose code ships in the package. */
    private const SHIPPED_DIRS = [
        'Api', 'Block', 'Console', 'Controller', 'Cron', 'Model', 'Observer', 'Plugin',
        'Setup', 'Ui', 'ViewModel', 'etc', 'view', 'compat',
    ];

    public function testDisconnectRemovesTheStoredKeyAndSendsNothingToTheEngine(): void
    {
        $store = new class {
            /** @var array<string, string> */
            public array $values = [
                Settings::XML_PATH_CONNECTED => '1',
                Settings::XML_PATH_API_KEY => 'encrypted',
                Settings::XML_PATH_BASE_URL => 'https://intelligence.smaily.com',
                Settings::XML_PATH_ENDPOINTS
                    => '{"store_uninstall":"https://intelligence.smaily.com/api/v1/store/uninstall"}',
            ];
        };
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path): bool => ($store->values[$path] ?? '') === '1'
        );
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path): ?string => $store->values[$path] ?? null
        );
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('delete')->willReturnCallback(
            static function (string $path) use ($store): void {
                unset($store->values[$path]);
            }
        );
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('decrypt')->willReturn('sk_live_key');
        $settings = new Settings(
            $scopeConfig,
            $writer,
            $encryptor,
            $this->createMock(TypeListInterface::class),
            new Json(),
            $this->createMock(DateTime::class)
        );

        $tester = new CommandTester(new EngineDisconnectCommand($settings));
        $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([], $store->values, 'Disconnect removes the stored engine key and connection.');
    }

    public function testNeitherDisconnectNorTheSettingsItClearsCanReachTheEngine(): void
    {
        foreach ([EngineDisconnectCommand::class, Settings::class] as $class) {
            $constructor = (new ReflectionClass($class))->getConstructor();
            self::assertNotNull($constructor);
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                self::assertInstanceOf(ReflectionNamedType::class, $type);
                foreach (self::HTTP_NAMESPACES as $namespace) {
                    self::assertStringStartsNotWith(
                        $namespace,
                        $type->getName(),
                        "{$class} must not get anything that sends a request (CON-143)."
                    );
                }
            }
        }
    }

    public function testUninstallMakesNoEngineRequest(): void
    {
        foreach ([Uninstall::class, RemoveSettingsOnUninstall::class] as $class) {
            $file = (string)(new ReflectionClass($class))->getFileName();
            $source = (string)file_get_contents($file);

            self::assertNotSame('', $source);
            foreach (['Engine\\Client', 'GuzzleHttp', 'curl_', 'store/uninstall', 'store_uninstall'] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $source,
                    "{$class} must make no engine request (CON-143): found '{$needle}'."
                );
            }
        }
    }

    public function testNoShippedCodeNamesTheStoreDeletionEndpoint(): void
    {
        $root = dirname(__DIR__, 4);
        $found = [];
        foreach (self::SHIPPED_DIRS as $dir) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                $path = (string)$file;
                if (!preg_match('/\.(php|phtml|js|xml)$/', $path)) {
                    continue;
                }
                $code = (string)file_get_contents($path);
                if (str_contains($code, 'store/uninstall') || str_contains($code, 'store_uninstall')) {
                    $found[] = substr($path, strlen($root) + 1);
                }
            }
        }

        self::assertSame([], $found, 'No extension code may call the §16 store-deletion endpoint (CON-143).');
    }
}
