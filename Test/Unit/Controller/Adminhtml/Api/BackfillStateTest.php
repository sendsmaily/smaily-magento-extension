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
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Api\BackfillState;
use Smaily\Connect\Model\Backfill\EngineImportGuard;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\JobStatusAggregator;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\Collection;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;

/**
 * PRO-1969, PRO-3742: a Campaign Intelligence import (catalog, customers,
 * orders) started while Campaign Intelligence is not connected recorded
 * every row as failed. The start is refused instead, with the reason beside
 * the Start import button. The contacts import goes to Smaily and starts.
 */
class BackfillStateTest extends TestCase
{
    private JobManager&MockObject $jobManager;

    /** @var array<int|string, mixed> */
    private array $response = [];

    protected function setUp(): void
    {
        $this->jobManager = $this->createMock(JobManager::class);
    }

    /**
     * @dataProvider engineImports
     */
    public function testAnEngineImportDoesNotStartWhileCampaignIntelligenceIsNotConnected(
        string $jobType,
        string $message
    ): void {
        $this->jobManager->expects(self::never())->method('startIfIdle');

        $this->controller(false, ['action' => 'start', 'job_type' => $jobType])->execute();

        self::assertSame($message, $this->response['message']);
        self::assertSame('idle', $this->response['status']);
    }

    /**
     * @dataProvider engineImports
     */
    public function testAnEngineImportStartsWhileCampaignIntelligenceIsConnected(string $jobType): void
    {
        $this->jobManager->expects(self::once())->method('startIfIdle')
            ->with($jobType, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
            ->willReturn($this->createMock(Job::class));

        $this->controller(true, ['action' => 'start', 'job_type' => $jobType])->execute();

        self::assertArrayNotHasKey('message', $this->response);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function engineImports(): array
    {
        return [
            'catalog' => [
                Job::TYPE_CATALOG,
                'Campaign Intelligence is not connected, so there is nowhere to send the catalog.',
            ],
            'customers' => [
                Job::TYPE_CUSTOMERS,
                'Campaign Intelligence is not connected, so there is nowhere to send the customer data.',
            ],
            'orders' => [
                Job::TYPE_ORDERS,
                'Campaign Intelligence is not connected, so there is nowhere to send the order data.',
            ],
        ];
    }

    public function testTheContactsImportStartsWhileCampaignIntelligenceIsNotConnected(): void
    {
        $this->jobManager->expects(self::once())->method('startIfIdle')
            ->with(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1)
            ->willReturn($this->createMock(Job::class));

        $this->controller(false, ['action' => 'start', 'job_type' => Job::TYPE_CONTACTS])->execute();

        self::assertArrayNotHasKey('message', $this->response);
    }

    /**
     * @param array<string, string> $body
     */
    private function controller(bool $connected, array $body): BackfillState
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn($connected);

        $website = $this->createMock(\Magento\Store\Api\Data\WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);

        return new BackfillState(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $this->jobManager,
            $collectionFactory,
            $storeManager,
            $this->createMock(TimezoneInterface::class),
            new JobStatusAggregator(),
            new EngineImportGuard($engineSettings)
        );
    }
}
