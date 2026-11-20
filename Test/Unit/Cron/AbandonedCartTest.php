<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\AbandonedCart;

/**
 * CON-368: the longest abandoned-cart cutoff is derived from the scan's
 * 24-hour window and the interval the scan runs at; that interval is the
 * one etc/crontab.xml schedules. The scan itself, run after run at the
 * longest cutoff and one minute past it, is
 * Test/Integration/Cron/AbandonedCartTest.
 */
class AbandonedCartTest extends TestCase
{
    public function testTheScanRunsAtTheIntervalTheLongestCutoffAllowsFor(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/crontab.xml');
        self::assertNotFalse($xml);
        $job = $xml->xpath('//job[@name="smaily_abandoned_cart"]')[0] ?? null;
        self::assertNotNull($job);

        self::assertSame('*/' . (AbandonedCart::RUN_INTERVAL_SECONDS / 60) . ' * * * *', (string)$job->schedule);
    }

    public function testTheLongestCutoffLeavesOneRunBeforeTheWindowEnds(): void
    {
        self::assertSame(1435, AbandonedCart::MAX_CUTOFF_MINUTES);
        self::assertSame(86400, AbandonedCart::MAX_CUTOFF_MINUTES * 60 + AbandonedCart::RUN_INTERVAL_SECONDS);
    }
}
