<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\EngineCustomersProcessor;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * CON-227: a customer whose row Magento cannot read is skipped and counted
 * as skipped; the customers after it still go, and the job moves past it.
 * CON-241: only the customers in scope are read, by the history horizon,
 * and each row carries its job.
 */
class EngineCustomersProcessorTest extends TestCase
{
    public function testACustomerMagentoCannotReadIsSkippedAndTheImportGoesOn(): void
    {
        $builder = $this->createMock(CustomerPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static function (CustomerInterface $customer): array {
                if ((int)$customer->getId() === 22) {
                    throw new NoSuchEntityException();
                }

                return ['external_id' => (string)$customer->getId()];
            }
        );

        $enqueued = [];
        $ingestQueue = $this->createMock(IngestQueue::class);
        $ingestQueue->method('countPending')->willReturn(0);
        $ingestQueue->method('enqueue')->willReturnCallback(
            static function (string $domain, array $item, ?string $entityId, $storeId, $uuid, ?int $jobId) use (&$enqueued): bool {
                $enqueued[] = [$item['external_id'], $jobId];

                return true;
            }
        );

        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(6);
        $job->method('getData')->willReturn(null);
        $job->expects(self::once())->method('setData')->with('total_count', 3);
        $skippedRow = $this->createMock(SkippedRow::class);
        $skippedRow->expects(self::once())->method('record')
            ->with($job, 22, self::isInstanceOf(NoSuchEntityException::class));
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with($job, 2, 1, '23');
        $jobManager->expects(self::once())->method('complete')->with($job);

        $results = $this->createMock(CustomerSearchResultsInterface::class);
        $results->method('getItems')->willReturn([$this->customer(21), $this->customer(22), $this->customer(23)]);
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);

        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->expects(self::once())->method('addFilter')->with('entity_id', [21, 22, 23], 'in')
            ->willReturnSelf();
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('horizonNow')->willReturn('2021-10-28 12:00:00');
        $scope = $this->createMock(EngineCustomerScope::class);
        $scope->method('count')->with('2021-10-28 12:00:00')->willReturn(3);
        $pages = [[21, 22, 23], []];
        $scope->method('page')->willReturnCallback(
            static function (string $horizon, int $afterId) use (&$pages): array {
                return $horizon === '2021-10-28 12:00:00' ? (array)array_shift($pages) : [];
            }
        );

        (new EngineCustomersProcessor(
            $jobManager,
            $repository,
            $criteriaBuilder,
            $builder,
            $ingestQueue,
            $skippedRow,
            $scope,
            $historyImport
        ))->process($job);

        self::assertSame([['21', 6], ['23', 6]], $enqueued);
    }

    private function customer(int $id): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getEmail')->willReturn('c' . $id . '@example.com');

        return $customer;
    }
}
