<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

/**
 * The clock an engine import's tick budget reads
 * (Backfill\Engine*Processor): one tick queues pages until its budget is
 * spent or the backlog is full. A test gives the processor a clock that
 * does not move, so how fast the database answers does not change how much
 * one tick queues (CON-265).
 */
class ImportClock
{
    /**
     * @return float seconds
     */
    public function now(): float
    {
        return microtime(true);
    }
}
