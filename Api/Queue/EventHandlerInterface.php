<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Api\Queue;

use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Handles a batch of queued marketing events of a single event type.
 *
 * Implementations are registered in the Smaily\Connect\Model\Queue\HandlerPool
 * via di.xml, keyed by event type.
 */
interface EventHandlerInterface
{
    /**
     * Process a batch of events.
     *
     * @param Event[] $events all of the same event type
     * @return array<int, true|string|\Throwable|Failure|Skipped> map of
     *         queue row ID to true on success, the client's exception itself
     *         for a failed send (its type says retry or stop, Failure::of()),
     *         a Failure for the handler's own verdict (Failure::permanent()
     *         for a row that can never be sent), Skipped for a row closed
     *         without sending, or an error message for a failure that is
     *         retryable but carries no response
     */
    public function handle(array $events): array;
}
