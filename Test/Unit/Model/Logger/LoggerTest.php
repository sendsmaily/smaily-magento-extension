<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;

/**
 * The log level has no admin control: it is read from
 * `smaily_connect/logging/verbosity` (default scope), defaults to "error",
 * and a developer changes it with `bin/magento config:set`.
 */
class LoggerTest extends TestCase
{
    /**
     * @dataProvider levelProvider
     */
    public function testLevelGatesInfoAndDebug(string $level, int $infos, int $debugs): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')
            ->with(Config::XML_PATH_LOG_VERBOSITY)
            ->willReturn($level);

        $psrLogger = $this->createMock(LoggerInterface::class);
        $psrLogger->expects(self::once())->method('error');
        $psrLogger->expects(self::exactly($infos))->method('info');
        $psrLogger->expects(self::exactly($debugs))->method('debug');

        $logger = new Logger(
            $psrLogger,
            new Config($scopeConfig, $this->createMock(EncryptorInterface::class))
        );
        $logger->error('error');
        $logger->info('info');
        $logger->debug('debug');
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function levelProvider(): array
    {
        return [
            'error' => ['error', 0, 0],
            'info' => ['info', 1, 0],
            'debug' => ['debug', 1, 1],
        ];
    }

    public function testDefaultLevelIsErrorsOnly(): void
    {
        $config = simplexml_load_file(dirname(__DIR__, 4) . '/etc/config.xml');

        self::assertSame('error', (string)$config->default->smaily_connect->logging->verbosity);
    }

    /**
     * `bin/magento config:set` rejects a path that system.xml does not
     * declare, so the hidden section must keep this field.
     */
    public function testConfigSetCanFindThePath(): void
    {
        $system = simplexml_load_file(dirname(__DIR__, 4) . '/etc/adminhtml/system.xml');

        self::assertCount(
            1,
            $system->xpath('//section[@id="smaily_connect"]/group[@id="logging"]/field[@id="verbosity"]')
        );
    }
}
