<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;

/**
 * Starting an import, in one place for the admin import endpoint
 * (Controller/Adminhtml/Api/BackfillState `start`) and
 * smaily:backfill:start: the connection check first (EngineImportGuard —
 * a Campaign Intelligence import does not start while it is not connected),
 * then the websites (one job per Campaign Intelligence connection for an
 * engine import, one per website for the contacts import), then
 * JobManager::startIfIdle() for each — one active import of a type per
 * website or connection at a time, a stalled one cancelled first. Each
 * caller words the outcome for its own merchant. Connecting Campaign
 * Intelligence starts its imports through ImportsOnConnect instead: the
 * connection it has just stored is not read back within the same request,
 * so the check here would refuse them.
 *
 * An engine import's job carries the key of its connection as its
 * website_id (CON-237): for one website, the connection that serves it;
 * without one, every connected connection — under one store, the primary
 * alone (key 0, Job::ENGINE_WEBSITE_ID), as before.
 */
class ImportStarter
{
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly EngineImportGuard $engineImportGuard,
        private readonly Connections $connections
    ) {
    }

    /**
     * Start an import of this type, unless the connection check refuses it.
     *
     * @param string $target The type's target (Job::TYPE_TARGETS), as the caller resolved it.
     * @param int|null $websiteId An import for this website only (an engine import: for the
     *     connection that serves it); null for every website (every connected connection).
     * @return array{refusal: Phrase|null, jobs: array<int, Job|null>} the reason nothing started, or
     *     null and per website id or connection key (in start order) the job this call queued, or
     *     null when one was already queued or running
     */
    public function start(string $jobType, string $target, ?int $websiteId = null): array
    {
        if ($target === Job::TARGET_ENGINE) {
            $connections = $this->engineConnections($websiteId);
            $refusal = $this->engineImportGuard->refusal($jobType, $target, $connections[0] ?? null);
            if ($refusal !== null) {
                return ['refusal' => $refusal, 'jobs' => []];
            }
            $websiteIds = array_map(static fn (Connection $connection): int => $connection->key(), $connections);
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

    /**
     * The connections an engine import starts for: the one serving the
     * website, or every connected one; the primary when none is connected,
     * so that the guard names why.
     *
     * @return Connection[]
     */
    private function engineConnections(?int $websiteId): array
    {
        if ($websiteId !== null) {
            $connection = $this->connections->forWebsite($websiteId);

            return $connection === null ? [] : [$connection];
        }

        return $this->connections->connected() ?: [$this->connections->primary()];
    }
}
