<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
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
use Smaily\Connect\Model\Queue\RowWriter;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event\CollectionFactory;

class EventQueueTest extends TestCase
{
    private const NOW_TIMESTAMP = 1_800_000_000;

    private EventQueue $queue;
    private EventResource&MockObject $eventResource;
    private Event&MockObject $event;
    private DateTime&MockObject $dateTime;
    private EventFactory&MockObject $eventFactory;

    protected function setUp(): void
    {
        $this->eventResource = $this->createMock(EventResource::class);

        $dateTime = $this->createMock(DateTime::class);
        $this->dateTime = $dateTime;
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TIMESTAMP);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (string $format = 'Y-m-d H:i:s', $input = null): string =>
                gmdate($format, $input === null ? self::NOW_TIMESTAMP : (int)$input)
        );

        $this->event = $this->createMock(Event::class);

        $eventFactory = $this->createMock(EventFactory::class);
        $eventFactory->method('create')->willReturn($this->event);
        $this->eventFactory = $eventFactory;

        $this->queue = new EventQueue(
            $eventFactory,
            $this->eventResource,
            $this->createMock(CollectionFactory::class),
            $this->createMock(IdentityGeneratorInterface::class),
            new Json(),
            new PayloadDecoder(new Json()),
            $dateTime,
            $this->createMock(ResourceConnection::class),
            $this->createMock(Logger::class),
            new RowWriter($this->createMock(ResourceConnection::class))
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

    /**
     * PRO-1964: one refusal shared by a batch is one UPDATE and one log
     * line; each row keeps its own ladder step and its own exchange.
     */
    public function testMarkFailedManyWritesTheRowsInOneStatementAndLogsOnce(): void
    {
        $first = $this->row(['id' => 1, 'attempts' => 0, 'sent_payload' => '[{"email":"a@example.com"}]']);
        $second = $this->row(['id' => 2, 'attempts' => 0, 'sent_payload' => '[{"email":"b@example.com"}]']);
        $last = $this->row(['id' => 3, 'attempts' => EventQueue::MAX_ATTEMPTS - 1, 'sent_payload' => null]);

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );
        $connection->method('quote')->willReturnCallback(
            static fn ($value): string => is_int($value) ? (string)$value : "'" . addslashes((string)$value) . "'"
        );
        $updates = [];
        $connection->expects(self::once())->method('update')->willReturnCallback(
            function (string $table, array $bind, array $where) use (&$updates): int {
                $updates[] = [$bind, $where];
                return count($where['id IN (?)']);
            }
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error')->with(
            'Queue events failed permanently',
            ['count' => 1, 'event_type' => 'contact.sync', 'ids' => [3], 'error' => 'HTTP 503']
        );
        $this->eventResource->expects(self::never())->method('save');

        $this->queue($resource, $logger)->markFailedMany([$first, $second, $last], 'HTTP 503');

        [$bind, $where] = $updates[0];
        self::assertSame([1, 2, 3], $where['id IN (?)']);
        self::assertSame('HTTP 503', $bind['last_error'], 'A value every row shares is set once');
        self::assertSame(
            'CASE `id` WHEN 1 THEN 1 WHEN 2 THEN 1 WHEN 3 THEN 5 ELSE `attempts` END',
            (string)$bind['attempts']
        );
        self::assertSame(
            "CASE `id` WHEN 1 THEN 'pending' WHEN 2 THEN 'pending' WHEN 3 THEN 'failed' ELSE `status` END",
            (string)$bind['status']
        );
        $retryAt = gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP + EventQueue::BACKOFF_SECONDS[0]);
        self::assertSame(
            "CASE `id` WHEN 1 THEN '$retryAt' WHEN 2 THEN '$retryAt' WHEN 3 THEN NULL ELSE `next_retry_at` END",
            (string)$bind['next_retry_at']
        );
        self::assertStringContainsString('WHEN 3 THEN NULL', (string)$bind['sent_payload']);
        self::assertSame(Event::STATUS_FAILED, $last->getStatus(), 'The models hold the outcome too');
        self::assertSame(1, $first->getAttempts());
    }

    public function testMarkFailedManyOfOneRowIsMarkFailed(): void
    {
        $row = $this->row(['id' => 7, 'attempts' => 0]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $this->eventResource->expects(self::once())->method('save')->with($row);

        $this->queue($resource, $this->createMock(Logger::class))
            ->markFailedMany([$row], 'permanent_http_404: gone', null, true);

        self::assertSame(Event::STATUS_FAILED, $row->getStatus());
        self::assertSame('permanent_http_404: gone', $row->getData('last_error'));
    }

    /**
     * PRO-3962: a row parked alone is logged in the words and fields of a
     * parked group — one line with the count and the ids.
     */
    public function testARowParkedAloneIsLoggedLikeAGroup(): void
    {
        $row = $this->row(['id' => 7, 'attempts' => 0]);
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error')->with(
            'Queue events failed permanently',
            ['count' => 1, 'event_type' => 'contact.sync', 'ids' => [7], 'error' => 'permanent_http_404: gone']
        );

        $this->queue($this->createMock(ResourceConnection::class), $logger)
            ->markFailed($row, 'permanent_http_404: gone', terminal: true);
    }

    /**
     * A queue row model with real data handling and no database behind it.
     *
     * @param array<string, mixed> $data
     */
    private function row(array $data): Event
    {
        $event = $this->getMockBuilder(Event::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $event->setData($data + ['event_type' => 'contact.sync', 'status' => Event::STATUS_SENDING]);

        return $event;
    }

    private function queue(ResourceConnection $resource, Logger $logger): EventQueue
    {
        return new EventQueue(
            $this->eventFactory,
            $this->eventResource,
            $this->createMock(CollectionFactory::class),
            $this->createMock(IdentityGeneratorInterface::class),
            new Json(),
            new PayloadDecoder(new Json()),
            $this->dateTime,
            $resource,
            $logger,
            new RowWriter($resource)
        );
    }
}
