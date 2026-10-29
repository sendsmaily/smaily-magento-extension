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
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\ImportStarter;
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
 * PRO-3915: an import nothing has moved for an hour reads as stalled
 * (starting it again cancels it first: JobManager::startIfIdle(), the
 * integration JobManagerTest). PRO-3923: one that waits behind another
 * import names it. PRO-3927: one the tick has set aside reads as stalled
 * and waits behind nothing. PRO-4037: a contacts import that sent nothing
 * because the website's contact sync was not active names the website.
 * CON-233: a stopped import never answers an error's own text. CON-241:
 * the Orders card gets the history import's running part.
 */
class BackfillStateTest extends TestCase
{
    private JobManager&MockObject $jobManager;

    private HistoryProgress&MockObject $historyProgress;

    /** @var array<int|string, mixed> */
    private array $response = [];

    protected function setUp(): void
    {
        $this->jobManager = $this->createMock(JobManager::class);
        $this->historyProgress = $this->createMock(HistoryProgress::class);
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

    /**
     * CON-241: the Orders card's answer carries the running part of the
     * history import of its latest job; another import's answer none.
     */
    public function testTheOrdersAnswerCarriesTheHistoryImportsRunningPart(): void
    {
        $card = ['part' => 'start', 'sent' => 120, 'expected' => 400, 'percent' => 30,
            'start_months' => 18, 'horizon_months' => 60];
        $latest = $this->job(Job::STATUS_RUNNING, Job::TYPE_ORDERS);
        $this->historyProgress->expects(self::once())->method('card')->with($latest)->willReturn($card);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_ORDERS], [$latest])->execute();
        self::assertSame($card, $this->response['history']);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_CATALOG], [
            $this->job(Job::STATUS_RUNNING),
        ])->execute();
        self::assertNull($this->response['history']);
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
     * @dataProvider activeStatuses
     */
    public function testAStalledImportReadsAsStalled(string $status): void
    {
        $this->jobManager->method('isStalled')->with(Job::TYPE_CATALOG, Job::TARGET_ENGINE)->willReturn(true);
        $this->jobManager->method('activeMovedWithin')->with(JobManager::STALLED_SECONDS)->willReturn(false);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_CATALOG], [$this->job($status)])
            ->execute();

        self::assertSame($status, $this->response['status']);
        self::assertTrue($this->response['stalled']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function activeStatuses(): array
    {
        return ['queued' => [Job::STATUS_PENDING], 'running' => [Job::STATUS_RUNNING]];
    }

    public function testAnImportThatMovesIsNotStalled(): void
    {
        $this->jobManager->method('isStalled')->willReturn(false);

        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CATALOG],
            [$this->job(Job::STATUS_RUNNING)]
        )->execute();

        self::assertSame('running', $this->response['status']);
        self::assertFalse($this->response['stalled']);
    }

    public function testAFinishedImportIsNeverStalled(): void
    {
        $this->jobManager->expects(self::never())->method('isStalled');

        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CATALOG],
            [$this->job(Job::STATUS_COMPLETED)]
        )->execute();

        self::assertFalse($this->response['stalled']);
    }

    /**
     * PRO-3923: a stalled import that waits behind another one — while no
     * queued or running job moves, the job the tick takes first — names it.
     */
    public function testAStalledImportWaitingBehindAnotherImportNamesIt(): void
    {
        $this->jobManager->method('isStalled')->willReturn(true);
        $this->jobManager->method('activeMovedWithin')->willReturn(false);
        $this->jobManager->method('nextActive')->willReturn($this->job(Job::STATUS_RUNNING, Job::TYPE_CONTACTS));

        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CATALOG],
            [$this->job(Job::STATUS_PENDING)]
        )->execute();

        self::assertTrue($this->response['stalled']);
        self::assertSame(Job::TYPE_CONTACTS, $this->response['blocked_by']);
    }

    public function testAStalledImportFirstInLineIsBlockedByNothing(): void
    {
        $this->jobManager->method('isStalled')->willReturn(true);
        $this->jobManager->method('activeMovedWithin')->willReturn(false);
        $this->jobManager->method('nextActive')->willReturn($this->job(Job::STATUS_RUNNING, Job::TYPE_CATALOG));

        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CATALOG],
            [$this->job(Job::STATUS_RUNNING)]
        )->execute();

        self::assertTrue($this->response['stalled']);
        self::assertSame('', $this->response['blocked_by']);
    }

    public function testAnImportThatMovesIsBlockedByNothing(): void
    {
        $this->jobManager->method('isStalled')->willReturn(false);
        $this->jobManager->expects(self::never())->method('nextActive');

        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CATALOG],
            [$this->job(Job::STATUS_PENDING)]
        )->execute();

        self::assertSame('', $this->response['blocked_by']);
    }

    /**
     * PRO-3927: an import the tick has set aside reads as stalled, and the
     * imports behind it run, so it waits behind none of them.
     */
    public function testASetAsideImportWhileAnotherMovesIsBlockedByNothing(): void
    {
        $this->jobManager->method('isStalled')->willReturn(true);
        $this->jobManager->method('activeMovedWithin')->willReturn(true);
        $this->jobManager->expects(self::never())->method('nextActive');

        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CATALOG],
            [$this->job(Job::STATUS_RUNNING)]
        )->execute();

        self::assertTrue($this->response['stalled']);
        self::assertSame('', $this->response['blocked_by']);
    }

    public function testAContactsImportThatSentNothingBecauseSyncWasNotActiveNamesTheWebsite(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getWebsiteId')->willReturn(1);
        $job->method('getStatus')->willReturn(Job::STATUS_COMPLETED);
        $job->method('getData')->willReturnMap([
            ['total_count', null, 0],
            ['completed_at', null, null],
            ['error_message', null, Job::REASON_CONTACT_SYNC_INACTIVE],
        ]);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_CONTACTS], [$job])->execute();

        self::assertSame('completed', $this->response['status']);
        self::assertSame(['Main Website'], $this->response['sync_inactive']);
        self::assertSame('', $this->response['error'], 'Not an error: the import did what it should');
    }

    public function testAFinishedImportNamesNoWebsiteWhoseSyncWasNotActive(): void
    {
        $this->controller(
            true,
            ['action' => 'status', 'job_type' => Job::TYPE_CONTACTS],
            [$this->job(Job::STATUS_COMPLETED, Job::TYPE_CONTACTS)]
        )->execute();

        self::assertSame([], $this->response['sync_inactive']);
    }

    /**
     * CON-227: a Campaign Intelligence import counts the rows it skipped
     * because Magento could not read them in its failed_count; the card
     * reads them as skipped, not as failed rows to find in the Log.
     */
    public function testAnEngineImportAnswersItsFailedCountAsSkipped(): void
    {
        $job = $this->job(Job::STATUS_COMPLETED, Job::TYPE_ORDERS);
        $job->method('getProcessedCount')->willReturn(98);
        $job->method('getFailedCount')->willReturn(2);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_ORDERS], [$job])->execute();

        self::assertSame(98, $this->response['processed']);
        self::assertSame(2, $this->response['skipped']);
        self::assertSame(0, $this->response['failed']);
    }

    public function testAContactsImportAnswersItsFailedCountAsFailed(): void
    {
        $job = $this->job(Job::STATUS_COMPLETED, Job::TYPE_CONTACTS);
        $job->method('getFailedCount')->willReturn(3);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_CONTACTS], [$job])->execute();

        self::assertSame(3, $this->response['failed']);
        self::assertSame(0, $this->response['skipped']);
    }

    /**
     * CON-233: a stopped import answers where the error happened, or one of
     * the imports' own reasons; an error's text that an earlier version
     * stored, which can quote a customer's data, answers as ''.
     *
     * @dataProvider storedReasons
     */
    public function testAStoppedImportAnswersOnlyWhereTheErrorHappened(string $stored, string $shown): void
    {
        $job = $this->job(Job::STATUS_FAILED, Job::TYPE_CUSTOMERS);
        $job->method('getData')->willReturnMap([
            ['total_count', null, 10],
            ['completed_at', null, null],
            ['error_message', null, $stored],
        ]);

        $this->controller(true, ['action' => 'status', 'job_type' => Job::TYPE_CUSTOMERS], [$job])->execute();

        self::assertSame('failed', $this->response['status']);
        self::assertSame($shown, $this->response['error']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function storedReasons(): array
    {
        $location = 'Magento\Framework\Exception\NoSuchEntityException at StoreRepository.php:110';

        return [
            'where the error happened' => [$location, $location],
            'a website without stores' => ['Website 3 has no stores', 'Website 3 has no stores'],
            'an earlier version\'s error text' => [
                'Unable to unserialize value. Error: Control character error, possibly incorrectly encoded',
                '',
            ],
            'an earlier version\'s text that ends like a location' => ['Order 17 failed at Json.php:42 for x', ''],
            'nothing' => ['', ''],
        ];
    }

    private function job(string $status, string $jobType = Job::TYPE_CATALOG): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getJobType')->willReturn($jobType);
        $job->method('getWebsiteId')->willReturn(Job::ENGINE_WEBSITE_ID);
        $job->method('getStatus')->willReturn($status);

        return $job;
    }

    /**
     * @param array<string, string> $body
     * @param Job[] $jobs the import's latest jobs
     */
    private function controller(bool $connected, array $body, array $jobs = []): BackfillState
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
        $collection->method('getItems')->willReturn($jobs);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn($connected);

        $website = $this->createMock(\Magento\Store\Api\Data\WebsiteInterface::class);
        $website->method('getId')->willReturn(1);
        $website->method('getName')->willReturn('Main Website');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        $storeManager->method('getWebsite')->with(1)->willReturn($website);

        return new BackfillState(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $this->jobManager,
            $collectionFactory,
            $storeManager,
            $this->createMock(TimezoneInterface::class),
            new JobStatusAggregator(),
            new ImportStarter($this->jobManager, $storeManager, new EngineImportGuard($engineSettings)),
            $this->historyProgress
        );
    }
}
