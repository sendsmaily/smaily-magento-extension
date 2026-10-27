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
use Smaily\Connect\Model\Backfill\ImportStarter;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\JobStatusAggregator;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;

/**
 * POST {action: start|status|cancel, job_type} -> aggregated progress:
 * {status, processed, failed, skipped, total, percent, finished_at, error, stalled,
 * blocked_by, sync_inactive}
 *
 * "start" starts jobs (per website for contacts, installation-wide for
 * engine types) and is idempotent — an already-active job is not an error.
 * A Campaign Intelligence import (catalog, customers, orders) does not
 * start while Campaign Intelligence is not connected: the answer then
 * carries a `message` saying why (PRO-1969, PRO-3742). The start is
 * Model/Backfill/ImportStarter, the one smaily:backfill:start shares.
 * "cancel" flips every active job of the type to cancelled; the background
 * worker stops at its next page boundary, and a later "start" begins a
 * fresh import. `stalled` marks a queued or running import that nothing
 * has moved for an hour, or one the tick has set aside
 * (JobManager::isStalled()'s rule, PRO-3915, PRO-3927); a "start"
 * cancels such an import first (JobManager::startIfIdle()), so it cannot
 * block the new one. `blocked_by` is the job type of the import a stalled
 * one waits behind — while no queued or running job moves, the job the
 * tick takes first (JobManager::nextActive()) — when that is another
 * import, else '' (PRO-3923, PRO-3927). `sync_inactive` names the websites
 * whose latest contacts job sent nothing because their contact sync was not
 * active (Job::REASON_CONTACT_SYNC_INACTIVE, PRO-4037). A Campaign
 * Intelligence import's failed_count holds the rows it skipped because
 * Magento could not read them (Model/Backfill/SkippedRow, CON-227): it is
 * answered as `skipped`, and its `failed` stays 0.
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
        private readonly ImportStarter $importStarter
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
        if ($action === 'start') {
            // Idempotent: an already-active job is not an error.
            $refusal = $this->importStarter->start($jobType, $target)['refusal'];
            if ($refusal !== null) {
                return $this->jsonResponse($this->aggregate($jobType, $target) + ['message' => (string)$refusal]);
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
        $syncInactive = [];
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
            $reason = (string)($job->getData('error_message') ?? '');
            if ($reason === Job::REASON_CONTACT_SYNC_INACTIVE) {
                $syncInactive[] = $this->websiteName($job->getWebsiteId());
            } elseif ($error === '' && $reason !== '') {
                $error = $reason;
            }
        }

        $skipped = $target === Job::TARGET_ENGINE ? $failed : 0;
        $status = $this->statusAggregator->resolve($statuses);
        $stalled = in_array($status, [Job::STATUS_PENDING, Job::STATUS_RUNNING], true)
            && $this->jobManager->isStalled($jobType, $target);
        // Another import holds this one back only while the worker moves
        // none: the tick then keeps taking that one first (PRO-3927).
        $ahead = $stalled && !$this->jobManager->activeMovedWithin(JobManager::STALLED_SECONDS)
            ? $this->jobManager->nextActive()
            : null;

        return [
            'status' => $status,
            'processed' => $processed,
            'failed' => $failed - $skipped,
            'skipped' => $skipped,
            'total' => $total,
            'percent' => $total > 0 ? (int)floor(min(100, $processed / $total * 100)) : 0,
            'finished_at' => $this->formatFinishedAt($finishedAt),
            'error' => $error,
            'stalled' => $stalled,
            'blocked_by' => $ahead !== null && $ahead->getJobType() !== $jobType ? $ahead->getJobType() : '',
            'sync_inactive' => $syncInactive,
        ];
    }

    /**
     * The website's name as the admin shows it; its id when it is gone.
     */
    private function websiteName(int $websiteId): string
    {
        try {
            return (string)$this->storeManager->getWebsite($websiteId)->getName();
        } catch (\Exception) {
            return (string)$websiteId;
        }
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
