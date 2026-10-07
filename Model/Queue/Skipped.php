<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

/**
 * A handler's answer for a row it closes without sending anything
 * (PRO-3619): retrying cannot change the reason, and the reason is what the
 * Log shows for the row.
 */
class Skipped
{
    public function __construct(
        public readonly string $reason
    ) {
    }
}
