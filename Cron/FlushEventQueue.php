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
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Queue\RetryPolicy;
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
        private readonly Logger $logger,
        private readonly RetryPolicy $retryPolicy
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
            foreach ($events as $event) {
                // Park immediately: retrying cannot make a handler appear.
                $event->setData('attempts', EventQueue::MAX_ATTEMPTS - 1);
                $this->eventQueue->markFailed($event, sprintf('No handler registered for "%s"', $eventType));
            }

            return;
        }

        try {
            $results = $handler->handle($events);
        } catch (SmailyClientException $exception) {
            foreach ($events as $event) {
                $this->retryPolicy->apply($event, $exception);
            }
            $this->logger->info('Queue batch failed', [
                'event_type' => $eventType,
                'count' => count($events),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        foreach ($events as $event) {
            $result = $results[(int)$event->getId()] ?? 'Handler returned no result for event';
            if ($result === true) {
                $this->eventQueue->markSent($event);
            } elseif ($result instanceof Skipped) {
                $this->eventQueue->markSkipped($event, $result->reason);
            } elseif ($result instanceof SmailyClientException) {
                // A refused send: the policy decides retry vs. stop for good.
                $this->retryPolicy->apply($event, $result);
            } else {
                $this->eventQueue->markFailed($event, (string)$result);
            }
        }
    }
}
