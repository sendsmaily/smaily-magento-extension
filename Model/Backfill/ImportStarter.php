<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Starting an import, in one place for the admin import endpoint
 * (Controller/Adminhtml/Api/BackfillState `start`) and
 * smaily:backfill:start: the connection check first (EngineImportGuard —
 * a Campaign Intelligence import does not start while it is not connected),
 * then the websites (one installation-wide job for an engine import, one
 * per website for the contacts import), then JobManager::startIfIdle() for
 * each — one active import of a type per website at a time, a stalled one
 * cancelled first. Each caller words the outcome for its own merchant.
 * Connecting Campaign Intelligence starts its imports through
 * ImportsOnConnect instead: the connection it has just stored is not read
 * back within the same request, so the check here would refuse them.
 */
class ImportStarter
{
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly EngineImportGuard $engineImportGuard
    ) {
    }

    /**
     * Start an import of this type, unless the connection check refuses it.
     *
     * @param string $target The type's target (Job::TYPE_TARGETS), as the caller resolved it.
     * @param int|null $websiteId A contacts import for this website only; null for every website.
     *     An engine import is installation-wide and ignores it.
     * @return array{refusal: Phrase|null, jobs: array<int, Job|null>} the reason nothing started, or
     *     null and per website id (in start order) the job this call queued, or null when one was
     *     already queued or running
     */
    public function start(string $jobType, string $target, ?int $websiteId = null): array
    {
        $refusal = $this->engineImportGuard->refusal($jobType, $target);
        if ($refusal !== null) {
            return ['refusal' => $refusal, 'jobs' => []];
        }

        if ($target === Job::TARGET_ENGINE) {
            $websiteIds = [Job::ENGINE_WEBSITE_ID];
        } else {
            $websiteIds = $websiteId !== null
                ? [$websiteId]
                : array_map(static fn ($website) => (int)$website->getId(), $this->storeManager->getWebsites());
        }

        $jobs = [];
        foreach ($websiteIds as $id) {
            $jobs[$id] = $this->jobManager->startIfIdle($jobType, $target, $id);
        }

        return ['refusal' => null, 'jobs' => $jobs];
    }
}
