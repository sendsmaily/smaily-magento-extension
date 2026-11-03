<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * Historical catalog import: pages products through Engine\CatalogIngest —
 * the same funnel the live hooks use, so a paged row can never disagree with
 * a live one — and the flusher delivers at the engine's batch pace. A flood guard pauses the
 * job while the queue backlog is high, so live events are never starved.
 *
 * An import is one Campaign Intelligence connection's (CON-237,
 * EngineJobConnection): it pages the products of that connection's catalog
 * (CatalogProductLoader::loadPage()) and queues their rows for it only.
 */
class EngineCatalogProcessor implements ProcessorInterface
{
    private const PAGE_SIZE = 100;
    private const TIME_BUDGET_SECONDS = 15;

    public function __construct(
        private readonly JobManager $jobManager,
        private readonly IngestQueue $ingestQueue,
        private readonly CatalogIngest $catalogIngest,
        private readonly CatalogProductLoader $productLoader,
        private readonly ImportClock $clock,
        private readonly EngineJobConnection $jobConnection
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(Job $job): void
    {
        if ($this->ingestQueue->countPending(Client::DOMAIN_CATALOG) >= IngestPace::IMPORT_BACKLOG_ROWS) {
            return; // Let the flusher drain before enqueueing more.
        }
        $connection = $this->jobConnection->of($job);
        if ($connection === null) {
            return;
        }

        // Taken up before the count, which can die on a large store: a job
        // that dies before its first page is then running and not moved,
        // so the tick sets it aside like any other (PRO-3950).
        $this->jobManager->markRunning($job);
        if ($job->getData('total_count') === null) {
            // Same scope as the page (PRO-1353) — otherwise the progress-bar
            // total can disagree with the scoped pages on multi-store installs.
            $job->setData('total_count', $this->productLoader->count($connection));
        }

        $deadline = $this->clock->now() + self::TIME_BUDGET_SECONDS;
        do {
            $cursor = (int)$job->getCursorValue();
            $products = $this->productLoader->loadPage($cursor, self::PAGE_SIZE, $connection);
            if (!$products) {
                $this->jobManager->complete($job);

                return;
            }

            $processed = 0;
            $failed = 0;
            $newCursor = $cursor;
            foreach ($products as $product) {
                $newCursor = max($newCursor, (int)$product->getId());
                if ($this->catalogIngest->enqueueProduct($product, (int)$job->getId(), $connection)) {
                    $processed++;
                } else {
                    $failed++;
                }
            }
            $this->jobManager->recordProgress($job, $processed, $failed, (string)$newCursor);

            if ($this->jobManager->isCancelled($job)) {
                return; // Admin cancel — stop cleanly at the page boundary.
            }

            if ($this->ingestQueue->countPending(Client::DOMAIN_CATALOG) >= IngestPace::IMPORT_BACKLOG_ROWS) {
                return;
            }
        } while ($this->clock->now() < $deadline);
    }
}
