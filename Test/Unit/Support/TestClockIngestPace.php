<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Support;

use Smaily\Connect\Model\Engine\IngestPace;

/**
 * The ingest pace on a clock the test moves; a pause moves it and is
 * recorded.
 */
class TestClockIngestPace extends IngestPace
{
    /** @var float seconds */
    public float $clock = 0.0;

    /** @var float[] */
    public array $pauses = [];

    /**
     * @inheritDoc
     */
    protected function now(): float
    {
        return $this->clock;
    }

    /**
     * @inheritDoc
     */
    protected function pause(float $seconds): void
    {
        $this->pauses[] = $seconds;
        $this->clock += $seconds;
    }
}
