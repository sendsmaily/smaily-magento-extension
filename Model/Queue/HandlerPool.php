<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Api\Queue\PausableEventHandlerInterface;

/**
 * Registry of marketing event handlers, keyed by event type (di.xml).
 */
class HandlerPool
{
    /**
     * @param array<string, EventHandlerInterface> $handlers
     */
    public function __construct(
        private readonly array $handlers = []
    ) {
        foreach ($this->handlers as $eventType => $handler) {
            if (!$handler instanceof EventHandlerInterface) {
                throw new \InvalidArgumentException(
                    sprintf('Handler for event type "%s" must implement EventHandlerInterface', $eventType)
                );
            }
        }
    }

    public function get(string $eventType): ?EventHandlerInterface
    {
        return $this->handlers[$eventType] ?? null;
    }

    /**
     * The event types whose handler cannot send now (PRO-2466): their rows
     * are not claimed.
     *
     * @return string[]
     */
    public function pausedEventTypes(): array
    {
        $paused = [];
        foreach ($this->handlers as $eventType => $handler) {
            if ($handler instanceof PausableEventHandlerInterface && $handler->isPaused()) {
                $paused[] = (string)$eventType;
            }
        }

        return $paused;
    }
}
