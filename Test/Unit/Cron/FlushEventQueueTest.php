<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Queue\RetryPolicy;
use Smaily\Connect\Model\Queue\Skipped;

class FlushEventQueueTest extends TestCase
{
    private EventQueue&MockObject $eventQueue;

    protected function setUp(): void
    {
        $this->eventQueue = $this->createMock(EventQueue::class);
    }

    public function testNoEventsIsANoOp(): void
    {
        $this->eventQueue->method('claimBatch')->willReturn([]);
        $this->eventQueue->expects(self::never())->method('markSent');
        $this->eventQueue->expects(self::never())->method('markFailed');

        $this->createCron(new HandlerPool([]))->execute();
    }

    public function testMissingHandlerParksEventsPermanently(): void
    {
        $event = $this->createEvent(1, 'unknown.type');
        $this->eventQueue->method('claimBatch')->willReturn([$event]);

        // Attempts are bumped so markFailed parks the row immediately.
        $event->expects(self::once())->method('setData')
            ->with('attempts', EventQueue::MAX_ATTEMPTS - 1);
        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($event, self::stringContains('unknown.type'));

        $this->createCron(new HandlerPool([]))->execute();
    }

    public function testHandlerResultsAreMappedPerEvent(): void
    {
        $ok = $this->createEvent(1, 'contact.sync');
        $bad = $this->createEvent(2, 'contact.sync');
        $this->eventQueue->method('claimBatch')->willReturn([$ok, $bad]);

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->method('handle')->willReturn([1 => true, 2 => 'invalid email']);

        $this->eventQueue->expects(self::once())->method('markSent')->with($ok);
        $this->eventQueue->expects(self::once())->method('markFailed')->with($bad, 'invalid email');

        $this->createCron(new HandlerPool(['contact.sync' => $handler]))->execute();
    }

    public function testTransportFailureReschedulesWholeBatch(): void
    {
        $first = $this->createEvent(1, 'contact.sync');
        $second = $this->createEvent(2, 'contact.sync');
        $this->eventQueue->method('claimBatch')->willReturn([$first, $second]);

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->method('handle')->willThrowException(new TransportException('API down', 503));

        $this->eventQueue->expects(self::exactly(2))->method('markFailed');
        $this->eventQueue->expects(self::never())->method('markSent');

        $this->createCron(new HandlerPool(['contact.sync' => $handler]))->execute();
    }

    public function testPermanentRefusalParksTheWholeBatchAtOnce(): void
    {
        $first = $this->createEvent(1, 'contact.sync');
        $second = $this->createEvent(2, 'contact.sync');
        $this->eventQueue->method('claimBatch')->willReturn([$first, $second]);

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->method('handle')->willThrowException(new TransportException('Gone', 404));

        $this->eventQueue->expects(self::exactly(2))->method('markFailed')
            ->with(self::anything(), self::stringContains('permanent_http_404'), null, null, null, true);

        $this->createCron(new HandlerPool(['contact.sync' => $handler]))->execute();
    }

    public function testAPerEventRefusalIsClassifiedByTheRetryPolicy(): void
    {
        $refused = $this->createEvent(1, 'contact.sync');
        $slowedDown = $this->createEvent(2, 'contact.sync');
        $this->eventQueue->method('claimBatch')->willReturn([$refused, $slowedDown]);

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->method('handle')->willReturn([
            1 => new TransportException('Unprocessable', 422),
            2 => new TransportException('Slow down', 429, null, 90),
        ]);

        $calls = [];
        $this->eventQueue->method('markFailed')->willReturnCallback(
            function (
                Event $event,
                string $error,
                ?string $sentPayload,
                ?string $response,
                ?int $retryAfter,
                bool $terminal
            ) use (&$calls): void {
                $calls[(int)$event->getId()] = [$error, $retryAfter, $terminal];
            }
        );

        $this->createCron(new HandlerPool(['contact.sync' => $handler]))->execute();

        self::assertStringContainsString('permanent_http_422', $calls[1][0]);
        self::assertTrue($calls[1][2], 'A 422 is parked on the spot');
        self::assertSame(['Slow down', 90, false], $calls[2]);
    }

    /**
     * PRO-3619: a row the handler skipped is closed for good with its
     * reason — not delivered, not failed, nothing left to retry.
     */
    public function testASkippedRowIsClosedWithItsReason(): void
    {
        $event = $this->createEvent(3, 'contact.sync');
        $this->eventQueue->method('claimBatch')->willReturn([$event]);

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->method('handle')->willReturn([3 => new Skipped('Smaily does not have this contact')]);

        $this->eventQueue->expects(self::once())->method('markSkipped')
            ->with($event, 'Smaily does not have this contact');
        $this->eventQueue->expects(self::never())->method('markSent');
        $this->eventQueue->expects(self::never())->method('markFailed');

        $this->createCron(new HandlerPool(['contact.sync' => $handler]))->execute();
    }

    public function testMissingResultIsAFailure(): void
    {
        $event = $this->createEvent(5, 'contact.sync');
        $this->eventQueue->method('claimBatch')->willReturn([$event]);

        $handler = $this->createMock(EventHandlerInterface::class);
        $handler->method('handle')->willReturn([]);

        $this->eventQueue->expects(self::once())->method('markFailed')
            ->with($event, self::stringContains('no result'));

        $this->createCron(new HandlerPool(['contact.sync' => $handler]))->execute();
    }

    public function testHandlerPoolRejectsInvalidHandlers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HandlerPool(['contact.sync' => new \stdClass()]); // @phpstan-ignore argument.type
    }

    private function createCron(HandlerPool $pool): FlushEventQueue
    {
        return new FlushEventQueue(
            $this->eventQueue,
            $pool,
            $this->createMock(Logger::class),
            new RetryPolicy($this->eventQueue)
        );
    }

    private function createEvent(int $id, string $eventType): Event&MockObject
    {
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn($id);
        $event->method('getEventType')->willReturn($eventType);

        return $event;
    }
}
