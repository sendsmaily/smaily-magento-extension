<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Api\Queue;

/**
 * A handler that can be unable to send for a while, whatever the row
 * (PRO-2466): while it is paused, the queue does not claim its rows, so they
 * wait untouched and do not crowd out the rows other handlers can send.
 */
interface PausableEventHandlerInterface extends EventHandlerInterface
{
    /**
     * Whether this handler's rows must wait now.
     */
    public function isPaused(): bool;
}
