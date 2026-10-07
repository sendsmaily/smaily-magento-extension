<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Backfill\ContactsProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-1764: the website's stored contact-sync answer gates the historical
 * import exactly as it gates the live paths — with the switch off nothing
 * reaches the transport, a job that never started reports a total of 0
 * rather than promising a sync, and one already in flight is stopped with
 * the counts it really achieved.
 *
 * PRO-3582: the import sends the audience of the website's contact-sync mode,
 * and every contact goes with its real subscription status.
 */
class ContactsProcessorTest extends TestCase
{
    private const WEBSITE_ID = 2;

    public function testSyncDisabledSendsNothingAndReportsAZeroTotal(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        // Not even counted: an import that sends nothing must not quote a
        // number that promises otherwise.
        $audience = $this->createMock(ContactAudience::class);
        $audience->expects(self::never())->method('count');
        $audience->expects(self::never())->method('subscriberPage');

        $job = $this->createJob();
        $job->expects(self::once())->method('setData')->with('total_count', 0);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('complete')->with($job);
        $jobManager->expects(self::never())->method('recordProgress');

        $this->createProcessor($jobManager, $audience, $clientProvider, false)->process($job);
    }

    public function testSyncSwitchedOffMidImportStopsTheJobWithoutRewritingItsTotal(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $audience = $this->createMock(ContactAudience::class);
        $audience->expects(self::never())->method('subscriberPage');

        // 400 already counted, some of them already sent: the outcome must
        // stay truthful about that, not read "completed, 0".
        $job = $this->createJob(400);
        $job->expects(self::never())->method('setData');

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('cancel')->with($job);
        $jobManager->expects(self::never())->method('complete');

        $this->createProcessor($jobManager, $audience, $clientProvider, false)->process($job);
    }

    public function testSubscribersOnlySendsTheSubscribersWithTheirOwnStatus(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::once())
            ->method('post')
            ->with(SmailyClient::ENDPOINT_CONTACT, [
                ['email' => 'person@example.com', 'is_unsubscribed' => 0],
                ['email' => 'person-left@example.com', 'is_unsubscribed' => 1],
            ]);
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::once())->method('forStore')->with(5)->willReturn($client);

        $audience = $this->createAudience(SyncMode::MODE_CONSENT, 2);
        $audience->method('subscriberPage')->willReturnOnConsecutiveCalls([
            $this->row(77, 'person@example.com', true),
            $this->row(78, 'person-left@example.com', false),
        ], []);
        $audience->expects(self::never())->method('customerPage');

        $job = $this->createJob(null, ['0', '78']);
        $job->expects(self::once())->method('setData')->with('total_count', 2);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with($job, 2, 0, '78');
        $jobManager->expects(self::once())->method('complete')->with($job);

        $this->createProcessor($jobManager, $audience, $clientProvider, true, SyncMode::MODE_CONSENT)
            ->process($job);
    }

    /**
     * PRO-3610: "All customers" is the soft opt-in. A customer who never
     * answered the newsletter goes without is_unsubscribed, so Smaily keeps
     * an existing contact's status and creates a new one as subscribed; a
     * customer who unsubscribed in the store goes as unsubscribed.
     */
    public function testAllCustomersSendsTheSubscribersThenEveryOtherCustomerWithoutAStatus(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::exactly(2))
            ->method('post')
            ->willReturnCallback(function (string $endpoint, array $contacts): array {
                static $calls = 0;
                $expected = [
                    [
                        ['email' => 'person@example.com', 'is_unsubscribed' => 0],
                        ['email' => 'person-left@example.com', 'is_unsubscribed' => 1],
                    ],
                    [['email' => 'person-customer@example.com']],
                ];
                self::assertSame(SmailyClient::ENDPOINT_CONTACT, $endpoint);
                self::assertSame($expected[$calls++], $contacts);

                return [];
            });
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->with(5)->willReturn($client);

        $audience = $this->createAudience(SyncMode::MODE_LEGITIMATE_INTEREST, 2);
        $audience->method('subscriberPage')
            ->willReturnOnConsecutiveCalls([
                $this->row(77, 'person@example.com', true),
                $this->row(78, 'person-left@example.com', false),
            ], []);
        $audience->expects(self::exactly(2))->method('customerPage')
            ->willReturnCallback(fn (int $websiteId, int $afterId): array => $afterId === 0
                ? [$this->row(12, 'person-customer@example.com', null, 12)]
                : []);

        $job = $this->createJob(null, ['0', '78', 'customer:12']);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::exactly(2))->method('recordProgress')
            ->willReturnCallback(function (Job $job, int $processed, int $failed, ?string $cursor): void {
                static $calls = 0;
                self::assertSame([['78'], ['customer:12']][$calls++], [$cursor]);
            });
        $jobManager->expects(self::once())->method('complete')->with($job);

        $this->createProcessor($jobManager, $audience, $clientProvider, true, SyncMode::MODE_LEGITIMATE_INTEREST)
            ->process($job);
    }

    /**
     * PRO-3753: a page Smaily refuses as invalid data (203) goes again one
     * contact per request, so only the refused contact counts as failed.
     */
    public function testAnInvalidDataRefusalOfAPageSendsEachContactAlone(): void
    {
        $posted = [];
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::exactly(6))->method('post')
            ->willReturnCallback(function (string $endpoint, array $contacts) use (&$posted): array {
                $posted[] = array_column($contacts, 'email');
                if (count($contacts) > 1 || $contacts[0]['email'] === 'u3@example.invalid') {
                    throw new ApiException('Smaily API returned code 203: Invalid data', 203);
                }

                return [];
            });
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::once())->method('forStore')->with(5)->willReturn($client);

        $audience = $this->createAudience(SyncMode::MODE_CONSENT, 5);
        $page = array_map(fn (int $n): array => $this->row(70 + $n, 'u' . $n . '@example.invalid', true), range(1, 5));
        $audience->method('subscriberPage')->willReturnOnConsecutiveCalls($page, []);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with(self::anything(), 4, 1, '75');

        $this->createProcessor($jobManager, $audience, $clientProvider, true, SyncMode::MODE_CONSENT)
            ->process($this->createJob(null, ['0', '75']));

        $emails = array_map(static fn (int $n): string => 'u' . $n . '@example.invalid', range(1, 5));
        self::assertSame([$emails, ...array_map(static fn (string $email): array => [$email], $emails)], $posted);
    }

    public function testAnotherRefusalOfAPageFailsThePageInOneRequest(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::once())->method('post')
            ->willThrowException(new ApiException('Smaily API returned code 216: Unknown error', 216));
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn($client);

        $audience = $this->createAudience(SyncMode::MODE_CONSENT, 2);
        $audience->method('subscriberPage')->willReturnOnConsecutiveCalls([
            $this->row(71, 'u1@example.invalid', true),
            $this->row(72, 'u2@example.invalid', true),
        ], []);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with(self::anything(), 0, 2, '72');

        $this->createProcessor($jobManager, $audience, $clientProvider, true, SyncMode::MODE_CONSENT)
            ->process($this->createJob(null, ['0', '72']));
    }

    public function testCheckoutOptInOnlySendsNobodyAndReportsAZeroTotal(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $audience = $this->createMock(ContactAudience::class);
        $audience->expects(self::never())->method('subscriberPage');
        $audience->expects(self::never())->method('customerPage');

        $job = $this->createJob();
        $job->expects(self::once())->method('setData')->with('total_count', 0);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('complete')->with($job);
        $jobManager->expects(self::never())->method('recordProgress');

        $this->createProcessor($jobManager, $audience, $clientProvider, true, SyncMode::MODE_CHECKOUT_OPTIN)
            ->process($job);
    }

    public function testSwitchingAwayFromAllCustomersMidImportSendsNoMoreNonSubscribers(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $audience = $this->createAudience(SyncMode::MODE_CONSENT, 1);
        $audience->expects(self::never())->method('customerPage');

        $job = $this->createJob(3, ['customer:12']);

        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('complete')->with($job);
        $jobManager->expects(self::never())->method('recordProgress');

        $this->createProcessor($jobManager, $audience, $clientProvider, true, SyncMode::MODE_CONSENT)
            ->process($job);
    }

    private function createAudience(string $mode, int $count): ContactAudience&MockObject
    {
        $audience = $this->createMock(ContactAudience::class);
        $audience->method('storeIds')->with(self::WEBSITE_ID)->willReturn([5]);
        $audience->method('count')->with(self::WEBSITE_ID, $mode)->willReturn($count);

        return $audience;
    }

    /**
     * @return array{id: int, email: string, store_id: int, customer_id: int, subscribed: ?bool}
     */
    private function row(int $id, string $email, ?bool $subscribed, int $customerId = 0): array
    {
        return [
            'id' => $id,
            'email' => $email,
            'store_id' => 5,
            'customer_id' => $customerId,
            'subscribed' => $subscribed,
        ];
    }

    /**
     * @param list<string> $cursors the cursor the job holds on each read
     */
    private function createJob(?int $totalCount = null, array $cursors = ['0']): Job&MockObject
    {
        $job = $this->createMock(Job::class);
        $job->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $job->method('getData')->willReturn($totalCount);
        $job->method('getCursorValue')->willReturnOnConsecutiveCalls(...$cursors);

        return $job;
    }

    private function createProcessor(
        JobManager&MockObject $jobManager,
        ContactAudience&MockObject $audience,
        SmailyClientProvider&MockObject $clientProvider,
        bool $syncEnabled,
        string $mode = SyncMode::MODE_CONSENT
    ): ContactsProcessor {
        $payloadBuilder = $this->createMock(SubscriberPayloadBuilder::class);
        // As the real builder: a null status is left out of the payload.
        $payloadBuilder->method('build')->willReturnCallback(
            fn (string $email, int $storeId, ?bool $isUnsubscribed): array => $isUnsubscribed === null
                ? ['email' => $email]
                : ['email' => $email, 'is_unsubscribed' => $isUnsubscribed ? 1 : 0]
        );

        $config = $this->createMock(Config::class);
        $config->method('isSyncEnabled')->with(self::WEBSITE_ID)->willReturn($syncEnabled);

        $modeModel = $this->createMock(Mode::class);
        $modeModel->method('mode')->with(self::WEBSITE_ID)->willReturn($mode);

        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $searchResults = $this->createMock(CustomerSearchResultsInterface::class);
        $searchResults->method('getItems')->willReturn([]);
        $customerRepository->method('getList')->willReturn($searchResults);

        return new ContactsProcessor(
            $jobManager,
            $audience,
            $modeModel,
            $customerRepository,
            $criteriaBuilder,
            $payloadBuilder,
            $clientProvider,
            $config,
            $this->createMock(Logger::class)
        );
    }
}
