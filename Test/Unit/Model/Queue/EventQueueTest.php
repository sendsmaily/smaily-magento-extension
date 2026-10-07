<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventFactory;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event\CollectionFactory;

class EventQueueTest extends TestCase
{
    private const NOW_TIMESTAMP = 1_800_000_000;

    private EventQueue $queue;
    private EventResource&MockObject $eventResource;
    private Event&MockObject $event;

    protected function setUp(): void
    {
        $this->eventResource = $this->createMock(EventResource::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TIMESTAMP);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (string $format = 'Y-m-d H:i:s', $input = null): string =>
                gmdate($format, $input === null ? self::NOW_TIMESTAMP : (int)$input)
        );

        $this->event = $this->createMock(Event::class);

        $eventFactory = $this->createMock(EventFactory::class);
        $eventFactory->method('create')->willReturn($this->event);

        $this->queue = new EventQueue(
            $eventFactory,
            $this->eventResource,
            $this->createMock(CollectionFactory::class),
            $this->createMock(IdentityGeneratorInterface::class),
            new Json(),
            new PayloadDecoder(new Json()),
            $dateTime,
            $this->createMock(ResourceConnection::class),
            $this->createMock(Logger::class)
        );
    }

    public function testEnqueueSavesPendingEvent(): void
    {
        $captured = [];
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );
        $this->eventResource->expects(self::once())->method('save');

        $result = $this->queue->enqueue('contact.sync', ['email' => 'a@b.c'], '42', 1, 'fixed-uuid');

        self::assertTrue($result);
        self::assertSame('contact.sync', $captured['event_type']);
        self::assertSame('fixed-uuid', $captured['event_uuid']);
        self::assertSame(Event::STATUS_PENDING, $captured['status']);
        self::assertSame('{"email":"a@b.c"}', $captured['payload']);
    }

    public function testEnqueueSkipsDuplicateUuid(): void
    {
        $this->event->method('addData')->willReturnSelf();
        $this->eventResource->method('save')
            ->willThrowException(new AlreadyExistsException(__('duplicate')));

        self::assertFalse($this->queue->enqueue('contact.sync', [], null, 0, 'existing-uuid'));
    }

    public function testMarkFailedReschedulesWithBackoff(): void
    {
        $captured = [];
        $this->event->method('getAttempts')->willReturn(0);
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );
        $this->eventResource->expects(self::once())->method('save');

        $this->queue->markFailed($this->event, 'network error');

        self::assertSame(1, $captured['attempts']);
        self::assertSame(Event::STATUS_PENDING, $captured['status']);
        self::assertSame(
            gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP + EventQueue::BACKOFF_SECONDS[0]),
            $captured['next_retry_at']
        );
    }

    public function testMarkFailedUsesEscalatingBackoff(): void
    {
        $captured = [];
        $this->event->method('getAttempts')->willReturn(2);
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );

        $this->queue->markFailed($this->event, 'still failing');

        self::assertSame(3, $captured['attempts']);
        self::assertSame(
            gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP + EventQueue::BACKOFF_SECONDS[2]),
            $captured['next_retry_at']
        );
    }

    public function testMarkFailedParksEventAfterMaxAttempts(): void
    {
        $captured = [];
        $this->event->method('getAttempts')->willReturn(EventQueue::MAX_ATTEMPTS - 1);
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );

        $this->queue->markFailed($this->event, 'permanent');

        self::assertSame(EventQueue::MAX_ATTEMPTS, $captured['attempts']);
        self::assertSame(Event::STATUS_FAILED, $captured['status']);
        self::assertNull($captured['next_retry_at']);
    }

    public function testMarkFailedHonoursARequestedRetryDelay(): void
    {
        $captured = [];
        $this->event->method('getAttempts')->willReturn(0);
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );

        $this->queue->markFailed($this->event, 'slow down', null, null, 90);

        self::assertSame(
            gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP + 90),
            $captured['next_retry_at'],
            'The delay Smaily asked for wins over the ladder step'
        );
    }

    public function testMarkFailedCapsARequestedRetryDelayAtTheLadderCeiling(): void
    {
        $captured = [];
        $this->event->method('getAttempts')->willReturn(0);
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );

        $this->queue->markFailed($this->event, 'slow down', null, null, 7 * 86400);

        self::assertSame(
            gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP + 21600),
            $captured['next_retry_at'],
            'A wild header cannot park a row for days'
        );
    }

    public function testATerminalFailureParksWithoutSpendingTheRemainingAttempts(): void
    {
        $captured = [];
        $this->event->method('getAttempts')->willReturn(0);
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );
        $this->eventResource->expects(self::once())->method('save');

        $this->queue->markFailed($this->event, 'permanent_http_404: gone', null, null, null, true);

        self::assertSame(1, $captured['attempts']);
        self::assertSame(Event::STATUS_FAILED, $captured['status']);
        self::assertNull($captured['next_retry_at']);
        self::assertSame('permanent_http_404: gone', $captured['last_error']);
    }

    public function testMarkSentClearsErrorState(): void
    {
        $captured = [];
        $this->event->method('addData')->willReturnCallback(
            function (array $data) use (&$captured) {
                $captured = $data;
                return $this->event;
            }
        );
        $this->eventResource->expects(self::once())->method('save');

        $this->queue->markSent($this->event, '{"sent":1}', '{"code":101}');

        self::assertSame(Event::STATUS_SENT, $captured['status']);
        self::assertNull($captured['last_error']);
        self::assertSame('{"code":101}', $captured['last_response']);
    }
}
