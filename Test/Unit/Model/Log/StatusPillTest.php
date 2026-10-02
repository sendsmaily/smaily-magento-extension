<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Log;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Log\StatusPill;
use Smaily\Connect\Ui\Component\QueueStatusOptions;

/**
 * PRO-3565: the Log shows every status as a pill in the design pack's
 * colours — the grid and Details read the same variant.
 */
class StatusPillTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function statuses(): array
    {
        return [
            'waiting' => ['pending', 'pending'],
            'in progress' => ['sending', 'pending'],
            'delivered' => ['sent', 'sent'],
            'failed' => ['failed', 'failed'],
            'withdrawn' => ['withdrawn', 'neutral'],
            'skipped' => ['skipped', 'neutral'],
            'unknown' => ['something-else', 'neutral'],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testEachStatusHasItsPill(string $status, string $variant): void
    {
        self::assertSame($variant, (new StatusPill())->variant($status));
    }

    public function testEveryFilterableStatusHasItsOwnLabel(): void
    {
        $labels = [];
        foreach ((new QueueStatusOptions())->toOptionArray() as $option) {
            $labels[$option['value']] = (string)$option['label'];
        }

        self::assertSame(
            [
                'pending' => 'Pending',
                'sending' => 'Sending',
                'sent' => 'Sent',
                'failed' => 'Failed',
                'withdrawn' => 'Withdrawn',
                'skipped' => 'Skipped',
            ],
            $labels
        );
    }
}
