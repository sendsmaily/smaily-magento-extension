<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * Connecting Campaign Intelligence starts the full catalog import (PRO-3741)
 * and the customers import (PRO-3790): both connect paths — the admin
 * Connect (Controller/Adminhtml/Api/EngineExchange) and the configuration
 * save, also `bin/magento config:set` (Model/Config/Backend/
 * EngineSetupToken) — call this after a successful setup exchange. The
 * customers import gives the engine the Magento customer id (`external_id`)
 * of every account; live sync sends it only on a customer save, so an
 * account the engine knows only from orders (by email), or one created by
 * Magento's own customer import, is otherwise unknown by id. Each job is
 * queued as the admin Start import queues it, one active import of a type
 * at a time, so a reconnect while one is queued or running starts no
 * second one — unless it has stalled, which is cancelled first, as the
 * card's Run again does (PRO-3923). The catalog is queued first: the tick
 * runs the oldest active job, so the customers import follows it. The
 * jobs wait for the next smaily_backfill_tick run; cancelled before then
 * (the admin's Hold back, or a card's Cancel import), they send nothing.
 * The orders import stays the merchant's to start (PRO-3742).
 */
class ImportsOnConnect
{
    public function __construct(
        private readonly JobManager $jobManager
    ) {
    }

    /**
     * Queue the catalog import, then the customers import.
     *
     * @return array{catalog: bool, customers: bool} per import, true when this call queued it,
     *     false when one was already queued or running
     */
    public function start(): array
    {
        // In this order: the catalog job gets the lower id, so it runs first.
        $catalog = $this->startOne(Job::TYPE_CATALOG);

        return [
            Job::TYPE_CATALOG => $catalog,
            Job::TYPE_CUSTOMERS => $this->startOne(Job::TYPE_CUSTOMERS),
        ];
    }

    private function startOne(string $jobType): bool
    {
        return $this->jobManager->startIfIdle($jobType, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID) !== null;
    }
}
