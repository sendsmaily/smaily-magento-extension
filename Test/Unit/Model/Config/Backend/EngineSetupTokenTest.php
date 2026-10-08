<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\ValidatorException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Config\Backend\EngineSetupToken;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * PRO-3741, PRO-3790: connecting Campaign Intelligence by saving the setup
 * token in Stores > Configuration (or `bin/magento config:set`) starts the
 * catalog import, then the customers import, and says so for each; never
 * the orders import. A save without a token is no connection and starts
 * nothing.
 */
class EngineSetupTokenTest extends TestCase
{
    private Client&MockObject $client;

    private JobManager&MockObject $jobManager;

    private ManagerInterface&MockObject $messageManager;

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->jobManager = $this->createMock(JobManager::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
    }

    public function testConnectingStartsTheCatalogAndCustomersImportsAndSaysSo(): void
    {
        $this->client->method('setupExchange')->willReturn(['tenant_name' => 'Pilot', 'engine_version' => '1.8.2']);
        $started = [];
        $this->jobManager->expects(self::exactly(2))->method('startIfIdle')
            ->willReturnCallback(function (string $jobType, string $target, int $websiteId) use (&$started) {
                $started[] = [$jobType, $target, $websiteId];

                return $this->createMock(Job::class);
            });
        $notices = [];
        $this->messageManager->method('addNoticeMessage')
            ->willReturnCallback(function (string $message) use (&$notices) {
                $notices[] = $message;

                return $this->messageManager;
            });

        $this->model('tok_abc123')->beforeSave();

        self::assertSame([
            [Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID],
            [Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID],
        ], $started);
        self::assertCount(2, $notices);
        self::assertStringStartsWith('The catalog import has started:', $notices[0]);
        self::assertStringStartsWith('The customers import has started:', $notices[1]);
    }

    public function testAReconnectWhileBothImportsAreQueuedOrRunningStartsNoSecond(): void
    {
        $this->client->method('setupExchange')->willReturn(['tenant_name' => 'Pilot', 'engine_version' => '1.8.2']);
        $this->jobManager->expects(self::exactly(2))->method('startIfIdle')
            ->willReturn(null);
        $this->messageManager->expects(self::never())->method('addNoticeMessage');

        $this->model('tok_abc123')->beforeSave();
    }

    public function testOnlyTheImportThatStartedIsAnnounced(): void
    {
        $this->client->method('setupExchange')->willReturn(['tenant_name' => 'Pilot', 'engine_version' => '1.8.2']);
        $this->jobManager->method('startIfIdle')->willReturnCallback(
            fn (string $jobType) => $jobType === Job::TYPE_CUSTOMERS ? $this->createMock(Job::class) : null
        );
        $this->messageManager->expects(self::once())->method('addNoticeMessage')
            ->with(self::stringStartsWith('The customers import has started:'));

        $this->model('tok_abc123')->beforeSave();
    }

    public function testASaveWithoutATokenStartsNothing(): void
    {
        $this->client->expects(self::never())->method('setupExchange');
        $this->jobManager->expects(self::never())->method('startIfIdle');

        $this->model('')->beforeSave();
    }

    public function testAFailedConnectionStartsNothing(): void
    {
        $this->client->method('setupExchange')->willThrowException(new EngineRequestException('setup_token_not_found', 404));
        $this->jobManager->expects(self::never())->method('startIfIdle');

        $this->expectException(ValidatorException::class);
        $this->model('tok_abc123')->beforeSave();
    }

    private function model(string $value): EngineSetupToken
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(EventManager::class));

        $model = new EngineSetupToken(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->client,
            $this->createMock(Settings::class),
            $this->messageManager,
            new ImportsOnConnect($this->jobManager)
        );
        $model->setValue($value);

        return $model;
    }
}
