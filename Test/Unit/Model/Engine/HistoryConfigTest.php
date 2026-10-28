<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;

/**
 * CON-241 (contract v1.20.0 §1, §2, §25 sender rule 1): a history import
 * takes its window from the ping, else from the setup exchange, else 18 /
 * 60 / newest_first — and says so in the log only then.
 */
class HistoryConfigTest extends TestCase
{
    public function testTheWindowComesFromThePingFirst(): void
    {
        self::assertSame(
            ['start_months' => 12, 'horizon_months' => 36, 'order' => 'newest_first'],
            $this->config(
                ['ok' => true, 'config' => ['history' => ['start_months' => 12, 'horizon_months' => 36,
                    'order' => 'newest_first']]],
                ['history' => ['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first']]
            )->forNewImport()
        );
    }

    public function testAPingWithoutTheBlockOrOneThatFailsFallsBackToTheExchange(): void
    {
        $exchange = ['history' => ['start_months' => 18, 'horizon_months' => 36, 'order' => 'newest_first']];
        $expected = ['start_months' => 18, 'horizon_months' => 36, 'order' => 'newest_first'];

        self::assertSame($expected, $this->config(['ok' => true], $exchange)->forNewImport());
        self::assertSame(
            $expected,
            $this->config(new EngineTransportException('Engine unavailable', 503), $exchange)->forNewImport()
        );
        self::assertSame(
            $expected,
            $this->config(['config' => ['history' => ['start_months' => 61, 'horizon_months' => 60]]], $exchange)
                ->forNewImport(),
            'A start part longer than the horizon is not a window'
        );
    }

    public function testWithoutAnyBlockTheDefaultIsUsedAndLogged(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('info')
            ->with('Campaign Intelligence sent no history window; the import uses the default');

        self::assertSame(HistoryConfig::FALLBACK, $this->config(['ok' => true], [], $logger)->forNewImport());
        self::assertSame(HistoryConfig::FALLBACK, $this->config(['ok' => true], [])->stored());
    }

    /**
     * @param array<string, mixed>|\Throwable $ping
     * @param array<string, mixed> $exchangeConfig
     */
    private function config(array|\Throwable $ping, array $exchangeConfig, ?Logger $logger = null): HistoryConfig
    {
        $client = $this->createMock(Client::class);
        if ($ping instanceof \Throwable) {
            $client->method('ping')->willThrowException($ping);
        } else {
            $client->method('ping')->willReturn($ping);
        }
        $settings = $this->createMock(Settings::class);
        $settings->method('getEngineConfig')->willReturn($exchangeConfig);

        return new HistoryConfig($client, $settings, $logger ?? $this->createMock(Logger::class));
    }
}
