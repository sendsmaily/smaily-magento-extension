<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical customer import into the engine ingest queue.
 */
class EngineCustomersProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 200;
    private const TIME_BUDGET_SECONDS = 15;
    private const QUEUE_BACKLOG_LIMIT = 500;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder,
        private readonly CustomerPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        if ($this->ingestQueue->countPending(Client::DOMAIN_CUSTOMERS) >= self::QUEUE_BACKLOG_LIMIT) {
            return;
        }

        $this->jobManager->markRunning($job);

        $deadline = microtime(true) + self::TIME_BUDGET_SECONDS;
        do {
            $cursor = (int)$job->getCursorValue();
            $criteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $cursor, 'gt')
                ->addSortOrder(
                    $this->sortOrderBuilder->setField('entity_id')->setAscendingDirection()->create()
                )
                ->setPageSize(self::PAGE_SIZE)
                ->setCurrentPage(1)
                ->create();
            $results = $this->customerRepository->getList($criteria);

            if ($job->getData('total_count') === null) {
                $job->setData('total_count', $results->getTotalCount() + $job->getProcessedCount());
            }

            $customers = $results->getItems();
            if (!$customers) {
                $this->jobManager->complete($job);

                return;
            }

            $processed = 0;
            $newCursor = $cursor;
            foreach ($customers as $customer) {
                $newCursor = max($newCursor, (int)$customer->getId());
                if ((string)$customer->getEmail() !== '') {
                    $this->ingestQueue->enqueue(
                        Client::DOMAIN_CUSTOMERS,
                        $this->payloadBuilder->build($customer),
                        (string)$customer->getId()
                    );
                }
                $processed++;
            }
            $this->jobManager->recordProgress($job, $processed, 0, (string)$newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_CUSTOMERS) >= self::QUEUE_BACKLOG_LIMIT) {
                return;
            }
        } while (microtime(true) < $deadline);
    }
}
