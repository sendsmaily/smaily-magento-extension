<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Smaily\Connect\Model\Backfill\ImportClock;

/**
 * An import clock that does not move: a tick's budget is never spent, so
 * one tick queues pages until the backlog is full or the import is done,
 * however slow the database or the machine (CON-265).
 */
class FrozenImportClock extends ImportClock
{
    /**
     * @inheritDoc
     */
    public function now(): float
    {
        return 0.0;
    }
}
