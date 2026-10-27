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
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCustomersProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\SkippedRow;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * CON-227: a customer whose row Magento cannot read is skipped and counted
 * as skipped; the customers after it still go, and the job moves past it.
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
            static function (string $domain, array $item) use (&$enqueued): bool {
                $enqueued[] = $item['external_id'];

                return true;
            }
        );

        $job = $this->createMock(Job::class);
        $job->method('getData')->willReturn(3);
        $skippedRow = $this->createMock(SkippedRow::class);
        $skippedRow->expects(self::once())->method('record')
            ->with($job, 22, self::isInstanceOf(NoSuchEntityException::class));
        $jobManager = $this->createMock(JobManager::class);
        $jobManager->expects(self::once())->method('recordProgress')->with($job, 2, 1, '23');

        $pages = [[$this->customer(21), $this->customer(22), $this->customer(23)], []];
        $results = $this->createMock(CustomerSearchResultsInterface::class);
        $results->method('getTotalCount')->willReturn(3);
        $results->method('getItems')->willReturnCallback(static function () use (&$pages): array {
            return array_shift($pages) ?? [];
        });
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);

        $criteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $criteriaBuilder->method('addFilter')->willReturnSelf();
        $criteriaBuilder->method('addSortOrder')->willReturnSelf();
        $criteriaBuilder->method('setPageSize')->willReturnSelf();
        $criteriaBuilder->method('setCurrentPage')->willReturnSelf();
        $criteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $sortOrderBuilder = $this->createMock(SortOrderBuilder::class);
        $sortOrderBuilder->method('setField')->willReturnSelf();
        $sortOrderBuilder->method('setAscendingDirection')->willReturnSelf();
        $sortOrderBuilder->method('create')->willReturn($this->createMock(SortOrder::class));

        (new EngineCustomersProcessor(
            $jobManager,
            $repository,
            $criteriaBuilder,
            $sortOrderBuilder,
            $builder,
            $ingestQueue,
            $skippedRow
        ))->process($job);

        self::assertSame(['21', '23'], $enqueued);
    }

    private function customer(int $id): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getEmail')->willReturn('c' . $id . '@example.com');

        return $customer;
    }
}
