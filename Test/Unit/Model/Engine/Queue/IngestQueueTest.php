<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Queue\IngestEventFactory;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\Queue\RowWriter;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent\Collection;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent\CollectionFactory;

/**
 * PRO-3962: how the ingest queue records one failure that many rows share,
 * and the one error-log line it writes for the rows it parks. CON-237:
 * which rows a claim reads for a Campaign Intelligence connection.
 */
class IngestQueueTest extends TestCase
{
    private const NOW_TIMESTAMP = 1_800_000_000;

    private IngestEventResource&MockObject $eventResource;

    protected function setUp(): void
    {
        $this->eventResource = $this->createMock(IngestEventResource::class);
    }

    public function testMarkFailedManyWritesTheRowsInOneStatementAndLogsOnce(): void
    {
        $first = $this->row(['id' => 1, 'attempts' => 0, 'sent_payload' => '{"products":[{"sku":"A"}]}']);
        $second = $this->row(['id' => 2, 'attempts' => 0, 'sent_payload' => '{"products":[{"sku":"B"}]}']);
        $last = $this->row(['id' => 3, 'attempts' => IngestQueue::MAX_ATTEMPTS - 1, 'sent_payload' => null]);

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
                $updates[] = [$table, $bind, $where];
                return count($where['id IN (?)']);
            }
        );
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error')->with(
            'Ingest events failed permanently',
            ['count' => 1, 'domain' => 'catalog', 'ids' => [3], 'error' => 'HTTP 503']
        );
        $this->eventResource->expects(self::never())->method('save');

        $this->queue($resource, $logger)->markFailedMany([$first, $second, $last], 'HTTP 503');

        [$table, $bind, $where] = $updates[0];
        self::assertSame(IngestEventResource::TABLE_NAME, $table);
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
        $retryAt = gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP + IngestQueue::BACKOFF_SECONDS[0]);
        self::assertSame(
            "CASE `id` WHEN 1 THEN '$retryAt' WHEN 2 THEN '$retryAt' WHEN 3 THEN NULL ELSE `next_retry_at` END",
            (string)$bind['next_retry_at']
        );
        self::assertStringContainsString('WHEN 3 THEN NULL', (string)$bind['sent_payload']);
        self::assertSame(
            gmdate('Y-m-d H:i:s', self::NOW_TIMESTAMP),
            $bind['updated_at'],
            'The outcome dates every row (PRO-3961)'
        );
        self::assertNull($bind['claim_token']);
        self::assertNull($bind['claimed_at']);
        self::assertEqualsCanonicalizing(
            [
                'attempts',
                'status',
                'next_retry_at',
                'last_error',
                'sent_payload',
                'claim_token',
                'claimed_at',
                'updated_at',
            ],
            array_keys($bind),
            'Only the failure columns the models hold are written'
        );
        self::assertSame(IngestEvent::STATUS_FAILED, $last->getStatus(), 'The models hold the outcome too');
        self::assertSame(1, $first->getAttempts());
    }

    public function testMarkFailedManyOfOneRowIsMarkFailed(): void
    {
        $row = $this->row(['id' => 7, 'attempts' => 0]);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $this->eventResource->expects(self::once())->method('save')->with($row);

        $this->queue($resource, $this->createMock(Logger::class))
            ->markFailedMany([$row], 'HTTP 400: bad wrapper', true);

        self::assertSame(IngestEvent::STATUS_FAILED, $row->getStatus());
        self::assertSame('HTTP 400: bad wrapper', $row->getData('last_error'));
    }

    /**
     * A row parked alone is logged in the words and fields of a parked
     * group — one line with the count and the ids.
     */
    public function testARowParkedAloneIsLoggedLikeAGroup(): void
    {
        $row = $this->row(['id' => 7, 'attempts' => 0]);
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error')->with(
            'Ingest events failed permanently',
            ['count' => 1, 'domain' => 'catalog', 'ids' => [7], 'error' => 'price: must be a number']
        );

        $this->queue($this->createMock(ResourceConnection::class), $logger)
            ->markFailed($row, 'price: must be a number', true);
    }

    public function testARescheduledRowIsNotLogged(): void
    {
        $row = $this->row(['id' => 7, 'attempts' => 0]);
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::never())->method('error');

        $this->queue($this->createMock(ResourceConnection::class), $logger)->markFailed($row, 'HTTP 503');

        self::assertSame(IngestEvent::STATUS_PENDING, $row->getStatus());
    }

    /**
     * CON-237: a claim for a connection reads its rows only — the store
     * views it serves, and for the primary the rows of none (NULL, every
     * row queued before) too. A connection that serves every website, as
     * the primary of one store does, is not narrowed: the claim is the one
     * it always was. Without a connection (the catalog_changed markers)
     * nothing is narrowed either.
     *
     * @param string[] $expected the store_id conditions the claim adds
     * @dataProvider claimFilters
     */
    public function testAClaimReadsTheRowsOfItsConnection(?Connection $connection, array $expected): void
    {
        $wheres = [];
        $select = $this->createMock(Select::class);
        $select->method('where')->willReturnCallback(
            function (string $condition, mixed $value = null) use (&$wheres, $select): Select {
                $wheres[] = [$condition, $value];

                return $select;
            }
        );
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getItems')->willReturn([]);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        self::assertSame([], $this->queue(
            $this->createMock(ResourceConnection::class),
            $this->createMock(Logger::class),
            $collectionFactory
        )->claimBatch('catalog', 100, $connection));
        self::assertSame($expected, $wheres);
    }

    /**
     * @return array<string, array{0: Connection|null, 1: array<int, array{0: string, 1: mixed}>}>
     */
    public static function claimFilters(): array
    {
        $settings = (new \ReflectionClass(Settings::class))->newInstanceWithoutConstructor();

        return [
            'no connection' => [null, []],
            'the primary of one store' => [new Connection(0, $settings, [1, 2], [1, 2, 3], 1, true), []],
            'the primary of separate stores' => [
                new Connection(0, $settings, [1], [1, 4], 1, false),
                [['(store_id IS NULL OR store_id IN (?))', [1, 4]]],
            ],
            'a primary that serves no website' => [
                new Connection(0, $settings, [], [], null, false),
                [['store_id IS NULL', null]],
            ],
            'a website connection' => [
                new Connection(2, $settings, [2], [2, 3], 2, false),
                [['store_id IN (?)', [2, 3]]],
            ],
        ];
    }

    /**
     * CON-237: a website connection that serves no website has no rows;
     * the queue is not asked.
     */
    public function testAConnectionThatServesNoWebsiteClaimsNothing(): void
    {
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSelect')->willReturn($this->createMock(Select::class));
        $collection->expects(self::never())->method('getItems');
        $collectionFactory->method('create')->willReturn($collection);
        $settings = $this->createMock(Settings::class);

        self::assertSame([], $this->queue(
            $this->createMock(ResourceConnection::class),
            $this->createMock(Logger::class),
            $collectionFactory
        )->claimBatch('catalog', 100, new Connection(5, $settings, [], [], null, false)));
    }

    /**
     * A queue row model with real data handling and no database behind it.
     *
     * @param array<string, mixed> $data
     */
    private function row(array $data): IngestEvent
    {
        $event = $this->getMockBuilder(IngestEvent::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $event->setData($data + ['domain' => 'catalog', 'status' => IngestEvent::STATUS_SENDING]);

        return $event;
    }

    private function queue(
        ResourceConnection $resource,
        Logger $logger,
        ?CollectionFactory $collectionFactory = null
    ): IngestQueue {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(self::NOW_TIMESTAMP);
        $dateTime->method('gmtDate')->willReturnCallback(
            static fn (?string $format = null, $input = null): string =>
                gmdate($format ?? 'Y-m-d H:i:s', $input === null ? self::NOW_TIMESTAMP : (int)$input)
        );

        return new IngestQueue(
            $this->createMock(IngestEventFactory::class),
            $this->eventResource,
            $collectionFactory ?? $this->createMock(CollectionFactory::class),
            $this->createMock(IdentityGeneratorInterface::class),
            new Json(),
            new PayloadDecoder(new Json()),
            $dateTime,
            $resource,
            $logger,
            new RowWriter($resource)
        );
    }
}
