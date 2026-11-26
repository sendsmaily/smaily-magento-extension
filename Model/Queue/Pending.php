<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

/**
 * A handler's answer for a row it may not send now, through no fault of the
 * row: Campaign Intelligence refuses the account (contract §2 `403
 * tenant_inactive`, PRO-2451). The row goes back to pending exactly as it
 * was — no attempt spent, nothing recorded — and waits for the account to
 * be active again, as the engine ingest rows do (PRO-2466).
 *
 * With $delaySeconds the row waits that long first, also with no attempt
 * spent: a privacy request the engine's limit does not allow now
 * (Engine\PrivacyRequestBudget, CON-432).
 */
class Pending
{
    public function __construct(
        public readonly int $delaySeconds = 0
    ) {
    }
}
