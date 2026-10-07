<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

/**
 * The pill a Log status is drawn with (PRO-3565) — one answer for the grid's
 * status column and the Details header. The variants are the design pack's
 * queue-status pills (`.smaily-pill--{variant}`): a row still on its way is
 * amber, a delivered one green, a failed one red. A withdrawn or skipped row
 * was neither delivered nor failed, so it is grey; its label says which.
 */
class StatusPill
{
    private const VARIANTS = [
        'pending' => 'pending',
        'sending' => 'pending',
        'sent' => 'sent',
        'failed' => 'failed',
    ];

    public function variant(string $status): string
    {
        return self::VARIANTS[$status] ?? 'neutral';
    }
}
