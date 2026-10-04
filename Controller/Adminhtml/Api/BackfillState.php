<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\JobStatusAggregator;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;

/**
 * POST {action: start|status|cancel, job_type} -> aggregated progress:
 * {status, processed, failed, total, percent, finished_at, error}
 *
 * "start" starts jobs (per website for contacts, installation-wide for
 * engine types) and is idempotent — an already-active job is not an error.
 * A catalog import does not start while Campaign Intelligence is not
 * connected: the answer then carries a `message` saying why (PRO-1969).
 * "cancel" flips every active job of the type to cancelled; the background
 * worker stops at its next page boundary, and a later "start" begins a
 * fresh import.
 */
class BackfillState extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly JobManager $jobManager,
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone,
        private readonly JobStatusAggregator $statusAggregator,
        private readonly EngineSettings $engineSettings
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $jobType = (string)($body['job_type'] ?? Job::TYPE_CONTACTS);
        $target = Job::TYPE_TARGETS[$jobType] ?? null;
        if ($target === null) {
            return $this->jsonResponse(['status' => 'idle', 'error' => 'unknown job type'], 400);
        }

        $action = (string)($body['action'] ?? 'status');
        if ($action === 'start' && $jobType === Job::TYPE_CATALOG && !$this->engineSettings->isConnected()) {
            // Every product would be recorded as failed: there is nowhere to send them.
            return $this->jsonResponse($this->aggregate($jobType, $target) + [
                'message' => (string)__('Campaign Intelligence is not connected, so there is nowhere to send the catalog.'),
            ]);
        }

        if ($action === 'start') {
            $websiteIds = $target === Job::TARGET_ENGINE
                ? [Job::ENGINE_WEBSITE_ID]
                : array_map(static fn ($website) => (int)$website->getId(), $this->storeManager->getWebsites());
            foreach ($websiteIds as $websiteId) {
                try {
                    $this->jobManager->start($jobType, $target, $websiteId);
                } catch (\RuntimeException) {
                    // Already active — idempotent start.
                }
            }
        } elseif ($action === 'cancel') {
            $this->jobManager->requestCancel($jobType, $target);
        }

        return $this->jsonResponse($this->aggregate($jobType, $target));
    }

    /**
     * Aggregate the most recent job(s) of a type into one progress row.
     *
     * @return array<string, mixed>
     */
    private function aggregate(string $jobType, string $target): array
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter('job_type', $jobType)
            ->addFieldToFilter('target', $target)
            ->setOrder('id', 'DESC')
            ->setPageSize(10);

        $processed = 0;
        $failed = 0;
        $total = 0;
        $statuses = [];
        $seenWebsites = [];
        $finishedAt = '';
        $error = '';
        foreach ($collection->getItems() as $job) {
            if (!$job instanceof Job || isset($seenWebsites[$job->getWebsiteId()])) {
                continue;
            }
            $seenWebsites[$job->getWebsiteId()] = true;
            $processed += $job->getProcessedCount();
            $failed += $job->getFailedCount();
            $total += (int)($job->getData('total_count') ?? 0);
            $statuses[] = $job->getStatus();
            $finishedAt = max($finishedAt, (string)($job->getData('completed_at') ?? ''));
            if ($error === '' && (string)($job->getData('error_message') ?? '') !== '') {
                $error = (string)$job->getData('error_message');
            }
        }

        $status = $this->statusAggregator->resolve($statuses);

        return [
            'status' => $status,
            'processed' => $processed,
            'failed' => $failed,
            'total' => $total,
            'percent' => $total > 0 ? (int)floor(min(100, $processed / $total * 100)) : 0,
            'finished_at' => $this->formatFinishedAt($finishedAt),
            'error' => $error,
        ];
    }

    /**
     * Store-timezone display string for a stored GMT timestamp.
     */
    private function formatFinishedAt(string $gmtTimestamp): string
    {
        if ($gmtTimestamp === '') {
            return '';
        }

        try {
            return $this->timezone->formatDateTime(
                new \DateTime($gmtTimestamp, new \DateTimeZone('UTC')),
                \IntlDateFormatter::MEDIUM,
                \IntlDateFormatter::SHORT
            );
        } catch (\Exception) {
            return $gmtTimestamp;
        }
    }
}
