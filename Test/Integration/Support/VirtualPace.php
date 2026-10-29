<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Smaily\Connect\Model\Engine\IngestPace;

/**
 * The real pace on a clock that moves only by its own waits: a send run
 * makes the same requests as at the real pace, at once, and its budget is
 * spent by the pace alone, however slow the database. now() tells when, on
 * that clock, a request went.
 */
class VirtualPace extends IngestPace
{
    /** @var float seconds since the pace was made, on its own clock */
    private float $virtualNow = 0.0;

    /**
     * @inheritDoc
     */
    public function now(): float
    {
        return $this->virtualNow;
    }

    /**
     * @inheritDoc
     */
    protected function pause(float $seconds): void
    {
        $this->virtualNow += $seconds;
    }
}
