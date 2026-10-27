<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

/**
 * How fast the engine ingest queue goes out (CON-232): the pace of one send
 * run (Cron\FlushIngestQueue), how long the run goes on, and how far an
 * import (Backfill\Engine*Processor) may queue ahead of it.
 *
 * A send run makes one request at a time, waits its turn before each, and
 * stops when its budget is spent; the next cron run carries on. The cron
 * group runs a job code in one process at a time (Magento's per-job lock),
 * and the budget ends a run before the next minute's run is due.
 */
class IngestPace
{
    /**
     * The pace: ingest requests one send run makes in a second, at most.
     * The contract allows 100 a second on each ingest route; a store's
     * history (catalog 100, customers 100, orders 50 rows a request) needs
     * far fewer. Engine contract 1.20.0 is to name a target pace — its
     * answer changes this one number.
     */
    public const REQUESTS_PER_SECOND = 5;

    /**
     * How long one send run goes on before it leaves the rest to the next
     * run: under the minute between two runs, with room for the import
     * tick that runs in the same cron group.
     */
    public const SEND_BUDGET_SECONDS = 40;

    /**
     * Rows of one domain an import queues ahead of the send run. A row the
     * store queues meanwhile (a new order) waits behind at most these:
     * 2,000 orders are 40 requests, which one run sends in its budget even
     * at a second an answer. An import therefore moves up to this many rows
     * of its kind a minute.
     */
    public const IMPORT_BACKLOG_ROWS = 2000;

    private float $deadline = 0.0;

    private float $nextRequestAt = 0.0;

    /**
     * Start a send run's budget.
     */
    public function start(): void
    {
        $now = $this->now();
        $this->deadline = $now + self::SEND_BUDGET_SECONDS;
        $this->nextRequestAt = $now;
    }

    /**
     * Whether the run's budget allows another request.
     */
    public function hasTime(): bool
    {
        return $this->now() < $this->deadline;
    }

    /**
     * Wait until the pace allows the next request.
     */
    public function awaitTurn(): void
    {
        $wait = $this->nextRequestAt - $this->now();
        if ($wait > 0) {
            $this->pause($wait);
        }
        $this->nextRequestAt = $this->now() + 1 / self::REQUESTS_PER_SECOND;
    }

    /**
     * @return float seconds
     */
    protected function now(): float
    {
        return microtime(true);
    }

    protected function pause(float $seconds): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        usleep((int)round($seconds * 1000000));
    }
}
