<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Api\EngineExchange;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * PRO-3741, PRO-3790: connecting Campaign Intelligence in the admin starts
 * the catalog import, then the customers import — never the orders import —
 * and the answer says which of them it started, so the page can show their
 * notices (the catalog one with its Hold back action).
 */
class EngineExchangeTest extends TestCase
{
    private Client&MockObject $client;

    private JobManager&MockObject $jobManager;

    /** @var array<string, mixed> */
    private array $response = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(Client::class);
        $this->jobManager = $this->createMock(JobManager::class);
    }

    public function testConnectingStartsTheCatalogImportThenTheCustomersImport(): void
    {
        $this->client->method('setupExchange')->willReturn(['tenant_name' => 'Pilot', 'engine_version' => '1.8.2']);
        $started = [];
        $this->jobManager->expects(self::exactly(2))->method('startIfIdle')
            ->willReturnCallback(function (string $jobType, string $target, int $websiteId) use (&$started) {
                $started[] = [$jobType, $target, $websiteId];

                return $this->createMock(Job::class);
            });

        $this->controller()->execute();

        self::assertSame([
            [Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID],
            [Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID],
        ], $started);
        self::assertTrue($this->response['connected']);
        self::assertTrue($this->response['catalogImportStarted']);
        self::assertTrue($this->response['customersImportStarted']);
    }

    public function testAReconnectWhileBothImportsAreQueuedOrRunningStartsNoSecond(): void
    {
        $this->client->method('setupExchange')->willReturn(['tenant_name' => 'Pilot', 'engine_version' => '1.8.2']);
        $this->jobManager->expects(self::exactly(2))->method('startIfIdle')
            ->willReturn(null);

        $this->controller()->execute();

        self::assertTrue($this->response['connected']);
        self::assertFalse($this->response['catalogImportStarted']);
        self::assertFalse($this->response['customersImportStarted']);
    }

    public function testTheAnswerSaysWhichImportStarted(): void
    {
        $this->client->method('setupExchange')->willReturn(['tenant_name' => 'Pilot', 'engine_version' => '1.8.2']);
        $this->jobManager->method('startIfIdle')->willReturnCallback(
            fn (string $jobType) => $jobType === Job::TYPE_CUSTOMERS ? $this->createMock(Job::class) : null
        );

        $this->controller()->execute();

        self::assertFalse($this->response['catalogImportStarted']);
        self::assertTrue($this->response['customersImportStarted']);
    }

    public function testAFailedConnectionStartsNothing(): void
    {
        $this->client->method('setupExchange')->willThrowException(new EngineRequestException('setup_token_not_found', 404));
        $this->jobManager->expects(self::never())->method('startIfIdle');

        $this->controller()->execute();

        self::assertFalse($this->response['connected']);
        self::assertArrayNotHasKey('catalogImportStarted', $this->response);
        self::assertArrayNotHasKey('customersImportStarted', $this->response);
    }

    private function controller(): EngineExchange
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode(['setup_url' => 'tok_abc123']));
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        return new EngineExchange(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $this->client,
            $this->createMock(Settings::class),
            new ImportsOnConnect($this->jobManager)
        );
    }
}
