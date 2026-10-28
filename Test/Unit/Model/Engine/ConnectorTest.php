<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connector;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * CON-156: the one connect step the admin Connect and the configuration
 * save share — exchange the setup token, store the connection, then start
 * the catalog import, the customers import and the orders import
 * (PRO-3741, PRO-3790, CON-241), in that order.
 */
class ConnectorTest extends TestCase
{
    private Client&MockObject $client;

    private Settings&MockObject $settings;

    private JobManager&MockObject $jobManager;

    /** @var array<int, string> */
    private array $steps = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->settings = $this->createMock(Settings::class);
        $this->jobManager = $this->createMock(JobManager::class);
    }

    public function testConnectingExchangesStoresThenStartsTheCatalogCustomersAndOrdersImports(): void
    {
        $exchange = ['tenant_name' => 'Pilot', 'engine_version' => '1.8.2'];
        $this->client->expects(self::once())->method('setupExchange')->with('tok_abc123')
            ->willReturnCallback(function () use ($exchange): array {
                $this->steps[] = 'exchange';

                return $exchange;
            });
        $this->settings->expects(self::once())->method('storeExchange')->with($exchange)
            ->willReturnCallback(function (): void {
                $this->steps[] = 'store';
            });
        $this->jobManager->method('startIfIdle')->willReturnCallback(
            function (string $jobType, string $target, int $websiteId): ?Job {
                $this->steps[] = sprintf('start %s %s %d', $jobType, $target, $websiteId);

                return $jobType === Job::TYPE_CATALOG ? $this->createMock(Job::class) : null;
            }
        );

        $result = $this->connector()->connect('tok_abc123');

        self::assertSame([
            'exchange',
            'store',
            sprintf('start %s %s %d', Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID),
            sprintf('start %s %s %d', Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID),
            sprintf('start %s %s %d', Job::TYPE_ORDERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID),
        ], $this->steps);
        self::assertSame($exchange, $result['exchange']);
        self::assertSame(
            [Job::TYPE_CATALOG => true, Job::TYPE_CUSTOMERS => false, Job::TYPE_ORDERS => false],
            $result['started']
        );
    }

    public function testAFailedExchangeStoresNothingAndStartsNothing(): void
    {
        $this->client->method('setupExchange')
            ->willThrowException(new EngineRequestException('setup_token_not_found', 404));
        $this->settings->expects(self::never())->method('storeExchange');
        $this->jobManager->expects(self::never())->method('startIfIdle');

        $this->expectException(EngineRequestException::class);
        $this->connector()->connect('tok_abc123');
    }

    private function connector(): Connector
    {
        return new Connector($this->client, $this->settings, new ImportsOnConnect($this->jobManager));
    }
}
