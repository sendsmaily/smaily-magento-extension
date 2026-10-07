<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * Connecting Campaign Intelligence starts the full catalog import (PRO-3741):
 * both connect paths — the admin Connect (Controller/Adminhtml/Api/
 * EngineExchange) and the configuration save, also `bin/magento config:set`
 * (Model/Config/Backend/EngineSetupToken) — call this after a successful
 * setup exchange. The job is queued as the admin Start import queues it, one
 * active catalog import at a time, so a reconnect while one is queued or
 * running starts no second import — unless it has stalled, which is
 * cancelled first, as the card's Run again does (PRO-3923). The job waits
 * for the next smaily_backfill_tick run; cancelled before then (the admin's
 * Hold back, or the card's Cancel import), it sends nothing.
 */
class CatalogImportOnConnect
{
    public function __construct(
        private readonly JobManager $jobManager
    ) {
    }

    /**
     * Queue the catalog import.
     *
     * @return bool true when this call queued it, false when one was already queued or running
     */
    public function start(): bool
    {
        return $this->jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID) !== null;
    }
}
