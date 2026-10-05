<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Drains the marketing event queue: claims due events, dispatches them to
 * per-event-type handlers and records per-event outcomes.
 */
class FlushEventQueue
{
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly HandlerPool $handlerPool,
        private readonly Logger $logger
    ) {
    }

    public function execute(): void
    {
        $this->eventQueue->requeueStale();

        $events = $this->eventQueue->claimBatch(self::BATCH_SIZE);
        if (!$events) {
            return;
        }

        $byType = [];
        foreach ($events as $event) {
            $byType[$event->getEventType()][] = $event;
        }

        foreach ($byType as $eventType => $typeEvents) {
            $this->dispatch((string)$eventType, $typeEvents);
        }
    }

    /**
     * @param Event[] $events
     */
    private function dispatch(string $eventType, array $events): void
    {
        $handler = $this->handlerPool->get($eventType);
        if ($handler === null) {
            // Retrying cannot make a handler appear (PRO-1961).
            $failure = Failure::permanent(sprintf('No handler registered for "%s"', $eventType));
            foreach ($events as $event) {
                $this->fail($event, $failure);
            }

            return;
        }

        try {
            $results = $handler->handle($events);
        } catch (SmailyClientException $exception) {
            $failure = Failure::of($exception);
            foreach ($events as $event) {
                $this->fail($event, $failure);
            }
            $this->logger->info('Queue batch failed', [
                'event_type' => $eventType,
                'count' => count($events),
                'error' => $exception->getSourceMessage(),
            ]);

            return;
        }

        foreach ($events as $event) {
            $result = $results[(int)$event->getId()] ?? 'Handler returned no result for event';
            if ($result === true) {
                $this->eventQueue->markSent($event);
            } elseif ($result instanceof Skipped) {
                $this->eventQueue->markSkipped($event, $result->reason);
            } elseif ($result instanceof Failure) {
                $this->fail($event, $result);
            } elseif ($result instanceof \Throwable) {
                // A failed send handed on whole: its type says retry or stop.
                $this->fail($event, Failure::of($result));
            } else {
                $this->eventQueue->markFailed($event, (string)$result);
            }
        }
    }

    /**
     * Park the row for good, or reschedule it, as the failure says.
     */
    private function fail(Event $event, Failure $failure): void
    {
        $this->eventQueue->markFailed(
            $event,
            $failure->reason,
            retryAfter: $failure->retryAfter,
            terminal: $failure->permanent
        );
    }
}
