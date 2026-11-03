<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\HistoryConfig;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-241 (contract v1.20.0 §1, §2, §25 sender rule 1): a history import
 * takes its window from the ping, else from the setup exchange, else 18 /
 * 60 / newest_first — and says so in the log only then.
 *
 * CON-237: each connection's window is its own engine store's.
 */
class HistoryConfigTest extends TestCase
{
    use EngineConnectionsStub;

    /** The connection config() built. */
    private Connection $connection;

    public function testTheWindowComesFromThePingFirst(): void
    {
        self::assertSame(
            ['start_months' => 12, 'horizon_months' => 36, 'order' => 'newest_first'],
            $this->config(
                ['ok' => true, 'config' => ['history' => ['start_months' => 12, 'horizon_months' => 36,
                    'order' => 'newest_first']]],
                ['history' => ['start_months' => 18, 'horizon_months' => 60, 'order' => 'newest_first']]
            )->forNewImport($this->connection)
        );
    }

    public function testAPingWithoutTheBlockOrOneThatFailsFallsBackToTheExchange(): void
    {
        $exchange = ['history' => ['start_months' => 18, 'horizon_months' => 36, 'order' => 'newest_first']];
        $expected = ['start_months' => 18, 'horizon_months' => 36, 'order' => 'newest_first'];

        self::assertSame($expected, $this->config(['ok' => true], $exchange)->forNewImport($this->connection));
        self::assertSame(
            $expected,
            $this->config(new EngineTransportException('Engine unavailable', 503), $exchange)->forNewImport($this->connection)
        );
        self::assertSame(
            $expected,
            $this->config(['config' => ['history' => ['start_months' => 61, 'horizon_months' => 60]]], $exchange)
                ->forNewImport($this->connection),
            'A start part longer than the horizon is not a window'
        );
    }

    public function testWithoutAnyBlockTheDefaultIsUsedAndLogged(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('info')
            ->with('Campaign Intelligence sent no history window; the import uses the default');

        self::assertSame(HistoryConfig::FALLBACK, $this->config(['ok' => true], [], $logger)->forNewImport($this->connection));
        self::assertSame(HistoryConfig::FALLBACK, $this->config(['ok' => true], [])->stored($this->connection));
    }

    /**
     * CON-237: a website connection's window is read from its engine store
     * — its ping through its client, else its stored exchange — never the
     * primary's.
     */
    public function testAWebsiteConnectionsWindowIsItsOwnEngineStores(): void
    {
        $primaryClient = $this->createMock(Client::class);
        $primaryClient->method('ping')->willReturn(['config' => ['history' => [
            'start_months' => 12, 'horizon_months' => 36,
        ]]]);
        $primarySettings = $this->createMock(Settings::class);
        $lvClient = $this->createMock(Client::class);
        $lvClient->method('ping')->willReturn(['ok' => true]);
        $lvSettings = $this->createMock(Settings::class);
        $lvSettings->method('getEngineConfig')->willReturn(['history' => [
            'start_months' => 6, 'horizon_months' => 24,
        ]]);
        $primary = $this->engineConnection(0, $primarySettings, [1], [1], 1);
        $lv = $this->engineConnection(2, $lvSettings, [2], [2], 2);
        $config = new HistoryConfig(
            $this->engineConnections([[$primary, $primaryClient], [$lv, $lvClient]]),
            $this->createMock(Logger::class)
        );

        self::assertSame(12, $config->forNewImport($primary)['start_months']);
        self::assertSame(6, $config->forNewImport($lv)['start_months']);
        self::assertSame(24, $config->stored($lv)['horizon_months']);
        self::assertSame(HistoryConfig::FALLBACK, $config->stored($primary));
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
        $connections = $this->oneEngineConnection($settings, $client);
        $this->connection = $connections->primary();

        return new HistoryConfig($connections, $logger ?? $this->createMock(Logger::class));
    }
}
