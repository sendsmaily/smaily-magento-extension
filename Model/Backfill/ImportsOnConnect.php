<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * Connecting Campaign Intelligence starts the history import (contract
 * v1.20.0 §25, CON-241): the full catalog import (PRO-3741), the customers
 * import (PRO-3790) and the orders import, newest first (HistoryImport).
 * Engine\Connector, the connect step both connect paths share — the admin
 * Connect (Controller/Adminhtml/Api/EngineExchange) and the configuration
 * save, also `bin/magento config:set` (Model/Config/Backend/EngineSetupToken)
 * — calls this after a successful setup exchange. It does not go through
 * ImportStarter: the connection check there reads the configuration, which
 * does not show the connection just stored until the next request. The
 * customers import gives the engine the Magento customer id (`external_id`)
 * of every customer in scope; live sync sends it only on a customer save,
 * so an account the engine knows only from orders (by email), or one
 * created by Magento's own customer import, is otherwise unknown by id.
 * Each job is queued as the admin Start import queues it, one active import
 * of a type at a time, so a reconnect while one is queued or running starts
 * no second one — unless it has stalled, which is cancelled first, as the
 * card's Run again does (PRO-3923). The jobs are queued in this order: the
 * tick runs the oldest active job, so the customers import follows the
 * catalog and the orders import follows the customers. The jobs wait for
 * the next smaily_backfill_tick run; cancelled before then (the admin's
 * Hold back, or a card's Cancel import), they send nothing.
 */
class ImportsOnConnect
{
    public function __construct(
        private readonly JobManager $jobManager
    ) {
    }

    /**
     * Queue the catalog import, then the customers import, then the orders import.
     *
     * @return array{catalog: bool, customers: bool, orders: bool} per import, true when this
     *     call queued it, false when one was already queued or running
     */
    public function start(): array
    {
        // In this order: the lower id runs first.
        $catalog = $this->startOne(Job::TYPE_CATALOG);
        $customers = $this->startOne(Job::TYPE_CUSTOMERS);

        return [
            Job::TYPE_CATALOG => $catalog,
            Job::TYPE_CUSTOMERS => $customers,
            Job::TYPE_ORDERS => $this->startOne(Job::TYPE_ORDERS),
        ];
    }

    private function startOne(string $jobType): bool
    {
        return $this->jobManager->startIfIdle($jobType, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID) !== null;
    }
}
