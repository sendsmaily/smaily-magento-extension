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
use Smaily\Connect\Model\Queue\Pending;
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

        $events = $this->eventQueue->claimBatch(self::BATCH_SIZE, $this->handlerPool->pausedEventTypes());
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
            $this->failAll($events, Failure::permanent(sprintf('No handler registered for "%s"', $eventType)));

            return;
        }

        try {
            $results = $handler->handle($events);
        } catch (SmailyClientException $exception) {
            $this->failAll($events, Failure::of($exception));
            $this->logger->info('Queue batch failed', [
                'event_type' => $eventType,
                'count' => count($events),
                'error' => $exception->getSourceMessage(),
            ]);

            return;
        }

        $waiting = [];
        $failed = [];
        foreach ($events as $event) {
            $result = $results[(int)$event->getId()] ?? 'Handler returned no result for event';
            if ($result instanceof Pending) {
                $waiting[] = $event;
            } elseif ($result === true) {
                $this->eventQueue->markSent($event);
            } elseif ($result instanceof Skipped) {
                $this->eventQueue->markSkipped($event, $result->reason);
            } elseif ($result instanceof Failure) {
                $this->collect($failed, $event, $result->reason, $result->retryAfter, $result->permanent);
            } elseif ($result instanceof \Throwable) {
                // A failed send handed on whole: its type says retry or stop.
                $failure = Failure::of($result);
                $this->collect($failed, $event, $failure->reason, $failure->retryAfter, $failure->permanent);
            } else {
                $this->collect($failed, $event, (string)$result, null, false);
            }
        }
        // One write per verdict (PRO-1964): the rows of one shared refusal
        // are recorded together, a row with a reason of its own alone.
        foreach ($failed as $group) {
            $this->eventQueue->markFailedMany(
                $group['events'],
                $group['reason'],
                $group['retryAfter'],
                $group['terminal']
            );
        }
        $this->eventQueue->release($waiting);
    }

    /**
     * Park the rows for good, or reschedule them, as their one failure says.
     *
     * @param Event[] $events
     */
    private function failAll(array $events, Failure $failure): void
    {
        $this->eventQueue->markFailedMany($events, $failure->reason, $failure->retryAfter, $failure->permanent);
    }

    /**
     * Group a failed row with the rows of the same verdict.
     *
     * @param array<string, array{reason: string, retryAfter: ?int, terminal: bool, events: Event[]}> $failed
     */
    private function collect(array &$failed, Event $event, string $reason, ?int $retryAfter, bool $terminal): void
    {
        $key = ($terminal ? 'T' : 'R') . ($retryAfter ?? '') . '|' . $reason;
        $failed[$key] ??= ['reason' => $reason, 'retryAfter' => $retryAfter, 'terminal' => $terminal, 'events' => []];
        $failed[$key]['events'][] = $event;
    }
}
