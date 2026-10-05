<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Whether a Log row waits for the Campaign Intelligence account to be
 * active again (PRO-3753), for the Details panel: a pending engine ingest
 * row while the account is refused (PRO-2451), or a pending row of a
 * marketing handler paused meanwhile (PRO-2466, PRO-3752). Neither is sent
 * at the next flush. Read from the facts the flushers use: the refusal and
 * HandlerPool's paused types.
 */
class AccountWait
{
    public function __construct(
        private readonly HandlerPool $handlerPool,
        private readonly EngineSettings $engineSettings
    ) {
    }

    /**
     * @param array<string, mixed> $row the row as QueueRowLoader reads it
     */
    public function waits(array $row): bool
    {
        if (($row['status'] ?? '') !== Event::STATUS_PENDING) {
            return false;
        }

        return ($row['source'] ?? '') === Collection::SOURCE_INTELLIGENCE
            ? $this->engineSettings->isRefused()
            : in_array((string)($row['type'] ?? ''), $this->handlerPool->pausedEventTypes(), true);
    }
}
