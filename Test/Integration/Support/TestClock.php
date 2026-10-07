<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Frozen, travel-able application clock. The queue classes read "now"
 * exclusively through Stdlib DateTime::gmtTimestamp()/gmtDate(), so
 * overriding gmtTimestamp() makes backoff and staleness deterministic
 * without sleeping in tests.
 */
class TestClock extends DateTime
{
    /**
     * @var int
     */
    private $now;

    /**
     * @param TimezoneInterface $localeDate
     */
    public function __construct(TimezoneInterface $localeDate)
    {
        parent::__construct($localeDate);
        $this->now = time();
    }

    /**
     * @inheritDoc
     */
    public function gmtTimestamp($input = null)
    {
        if ($input === null) {
            return $this->now;
        }

        return parent::gmtTimestamp($input);
    }

    /**
     * Move the frozen clock forward (or back, with a negative offset).
     */
    public function travel(int $seconds): void
    {
        $this->now += $seconds;
    }

    /**
     * Re-freeze the clock at the real current time (test isolation).
     */
    public function reset(): void
    {
        $this->now = time();
    }

    /**
     * The frozen "now" timestamp.
     */
    public function now(): int
    {
        return $this->now;
    }
}
