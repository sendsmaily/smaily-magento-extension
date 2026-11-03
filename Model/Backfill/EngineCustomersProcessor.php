<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical customer import into the engine ingest queue: the customers
 * in scope (EngineCustomerScope, CON-241), by customer id. The horizon is
 * the history import's (HistoryImport::horizonNow()).
 *
 * A customer whose row cannot be built from what Magento stores is skipped
 * and the import goes on (SkippedRow, CON-227).
 *
 * An import is one Campaign Intelligence connection's (CON-237,
 * EngineJobConnection): the customers in its scope, with its horizon, each
 * row built for it and queued for it.
 */
class EngineCustomersProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 200;
    private const TIME_BUDGET_SECONDS = 15;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly CustomerPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue,
        private readonly SkippedRow $skippedRow,
        private readonly EngineCustomerScope $scope,
        private readonly HistoryImport $historyImport,
        private readonly ImportClock $clock,
        private readonly EngineJobConnection $jobConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        if ($this->ingestQueue->countPending(Client::DOMAIN_CUSTOMERS) >= IngestPace::IMPORT_BACKLOG_ROWS) {
            return;
        }
        $connection = $this->jobConnection->of($job);
        if ($connection === null) {
            return;
        }

        $this->jobManager->markRunning($job);
        $horizon = $this->historyImport->horizonNow($connection);
        if ($job->getData('total_count') === null) {
            $job->setData('total_count', $this->scope->count($horizon, $connection));
        }

        $deadline = $this->clock->now() + self::TIME_BUDGET_SECONDS;
        $storeId = $connection->canonicalStoreId();
        do {
            $ids = $this->scope->page($horizon, (int)$job->getCursorValue(), self::PAGE_SIZE, $connection);
            if (!$ids) {
                $this->jobManager->complete($job);

                return;
            }

            $criteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $ids, 'in')
                ->setPageSize(self::PAGE_SIZE)
                ->setCurrentPage(1)
                ->create();
            $skipped = 0;
            foreach ($this->customerRepository->getList($criteria)->getItems() as $customer) {
                if ((string)$customer->getEmail() === '') {
                    continue;
                }
                try {
                    $item = $this->payloadBuilder->build($customer, $connection);
                } catch (\InvalidArgumentException | LocalizedException $exception) {
                    $this->skippedRow->record($job, (int)$customer->getId(), $exception);
                    $skipped++;
                    continue;
                }
                $this->ingestQueue->enqueue(
                    Client::DOMAIN_CUSTOMERS,
                    $item,
                    (string)$customer->getId(),
                    $storeId,
                    null,
                    (int)$job->getId()
                );
            }
            // A customer deleted since the page was read counts as processed.
            $this->jobManager->recordProgress($job, count($ids) - $skipped, $skipped, (string)max($ids));

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_CUSTOMERS) >= IngestPace::IMPORT_BACKLOG_ROWS) {
                return;
            }
        } while ($this->clock->now() < $deadline);
    }
}
