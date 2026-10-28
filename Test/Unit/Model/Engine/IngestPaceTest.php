<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\IngestPace;
use Smaily\Connect\Test\Unit\Support\TestClockIngestPace;

/**
 * CON-232: a send run makes at most REQUESTS_PER_SECOND requests a second
 * and stops asking for more once SEND_BUDGET_SECONDS have passed.
 */
class IngestPaceTest extends TestCase
{
    public function testRequestsAreSpacedToThePace(): void
    {
        $pace = new TestClockIngestPace();
        $pace->start();

        $starts = [];
        for ($request = 0; $request < 3; $request++) {
            $pace->awaitTurn();
            $starts[] = $pace->clock;
        }

        $interval = 1 / IngestPace::REQUESTS_PER_SECOND;
        self::assertEqualsWithDelta([0.0, $interval, 2 * $interval], $starts, 0.000001);
    }

    public function testASlowAnswerIsNotFollowedByAWait(): void
    {
        $pace = new TestClockIngestPace();
        $pace->start();
        $pace->awaitTurn();
        $pace->clock += 1.0; // The engine took a second to answer.

        $pace->awaitTurn();

        self::assertSame([], $pace->pauses);
    }

    public function testTheBudgetEndsTheRun(): void
    {
        $pace = new TestClockIngestPace();
        $pace->start();
        self::assertTrue($pace->hasTime());

        $pace->clock = IngestPace::SEND_BUDGET_SECONDS - 0.001;
        self::assertTrue($pace->hasTime());

        $pace->clock = IngestPace::SEND_BUDGET_SECONDS;
        self::assertFalse($pace->hasTime());

        $pace->start();
        self::assertTrue($pace->hasTime(), 'The next run starts a budget of its own');
    }
}
