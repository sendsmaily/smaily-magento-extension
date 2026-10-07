<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\JobStatusAggregator;

class JobStatusAggregatorTest extends TestCase
{
    /**
     * @dataProvider statusesProvider
     * @param string[] $statuses
     */
    public function testResolve(array $statuses, string $expected): void
    {
        self::assertSame($expected, (new JobStatusAggregator())->resolve($statuses));
    }

    /**
     * @return array<string, array{string[], string}>
     */
    public static function statusesProvider(): array
    {
        return [
            'no jobs at all' => [[], 'idle'],
            'queued, cron has not ticked yet (PRO-1397 #8)' => [['pending'], 'pending'],
            'queued on multiple websites, none started yet' => [['pending', 'pending'], 'pending'],
            'cron has started processing' => [['running'], 'running'],
            'one website running, another still queued — running wins' => [['pending', 'running'], 'running'],
            'aborted before an error' => [['failed'], 'failed'],
            'cancelled by an admin' => [['cancelled'], 'cancelled'],
            'finished cleanly' => [['completed'], 'completed'],
        ];
    }
}
