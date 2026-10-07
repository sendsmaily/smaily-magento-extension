<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\OrderIngest;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * PRO-1955: a refund saves the credit memo and the order one after the
 * other, in either order, and each save builds the order's row; one refund
 * makes one row, holding the later (complete) build.
 */
class OrderIngestTest extends TestCase
{
    private const OPEN = ['external_order_id' => '100000042', 'status' => 'processing', 'items' => []];
    private const RETURNED = [
        'external_order_id' => '100000042',
        'status' => 'processing',
        'items' => [['sku' => 'TENT-1', 'returned_at' => '2026-10-05T10:00:00Z']],
    ];
    private const CLOSED = [
        'external_order_id' => '100000042',
        'status' => 'refunded',
        'items' => [['sku' => 'TENT-1', 'returned_at' => '2026-10-05T10:00:00Z']],
    ];

    private OrderPayloadBuilder&MockObject $payloadBuilder;
    private IngestQueue&MockObject $queue;

    /** @var list<array{0: string, 1: array<string, mixed>, 2: ?string}> */
    private array $inserted = [];

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    private array $replaced = [];

    private bool $replaceTakes = true;

    protected function setUp(): void
    {
        $this->payloadBuilder = $this->createMock(OrderPayloadBuilder::class);
        $this->queue = $this->createMock(IngestQueue::class);
        $this->queue->method('enqueueReturningId')->willReturnCallback(
            function (string $domain, array $payload, ?string $entityId = null): int {
                $this->inserted[] = [$domain, $payload, $entityId];

                return 70 + count($this->inserted);
            }
        );
        $this->queue->method('replacePendingPayload')->willReturnCallback(
            function (int $id, array $payload): bool {
                $this->replaced[] = [$id, $payload];

                return $this->replaceTakes;
            }
        );
    }

    /**
     * Magento's admin refund: the credit memo is saved first, the order then
     * closes on its own save. The order's row holds the closed build.
     */
    public function testACreditMemoThenItsOrderSaveMakeOneRowWithTheLaterBuild(): void
    {
        $this->builds(self::RETURNED, self::CLOSED);
        $ingest = $this->ingest();

        $ingest->enqueue($this->order(), true);
        $ingest->enqueue($this->order());

        self::assertSame([[Client::DOMAIN_ORDERS, self::RETURNED, '100000042']], $this->inserted);
        self::assertSame([[71, self::CLOSED]], $this->replaced);
    }

    /**
     * The REST refunds save the order first, before the credit memo the
     * return is read from: the credit memo's build replaces it.
     */
    public function testAnOrderSaveThenItsCreditMemoMakeOneRowWithTheReturn(): void
    {
        $this->builds(self::OPEN, self::CLOSED);
        $ingest = $this->ingest();

        $ingest->enqueue($this->order());
        $ingest->enqueue($this->order(), true);

        self::assertCount(1, $this->inserted);
        self::assertSame([[71, self::CLOSED]], $this->replaced);
    }

    /**
     * A money refund that moves no state: the order save builds what the
     * credit memo's save built, and queues nothing more.
     */
    public function testAnIdenticalSecondBuildQueuesNothing(): void
    {
        $this->builds(self::RETURNED, self::RETURNED);
        $ingest = $this->ingest();

        $ingest->enqueue($this->order(), true);
        $ingest->enqueue($this->order());

        self::assertCount(1, $this->inserted);
        self::assertSame([], $this->replaced);
    }

    /**
     * The first row was claimed (or tried) meanwhile: it may have reached
     * the engine, so the later build gets a row of its own.
     */
    public function testARowAlreadyClaimedGetsARowAfterIt(): void
    {
        $this->builds(self::RETURNED, self::CLOSED);
        $this->replaceTakes = false;
        $ingest = $this->ingest();

        $ingest->enqueue($this->order(), true);
        $ingest->enqueue($this->order());

        self::assertSame([self::RETURNED, self::CLOSED], array_column($this->inserted, 1));
    }

    /**
     * Without a credit memo, each order save queues its row, as before.
     */
    public function testOrderSavesWithoutARefundEachQueueTheirRow(): void
    {
        $this->builds(self::OPEN, self::CLOSED);
        $ingest = $this->ingest();

        $ingest->enqueue($this->order());
        $ingest->enqueue($this->order());

        self::assertCount(2, $this->inserted);
        self::assertSame([], $this->replaced);
    }

    public function testAnotherOrdersSaveDoesNotTakeOverTheRow(): void
    {
        $other = ['external_order_id' => '100000043', 'status' => 'processing', 'items' => []];
        $this->builds(self::RETURNED, $other);
        $ingest = $this->ingest();

        $ingest->enqueue($this->order(), true);
        $ingest->enqueue($this->order('100000043'));

        self::assertSame(['100000042', '100000043'], array_column($this->inserted, 2));
        self::assertSame([], $this->replaced);
    }

    public function testAnOrderTheEngineCannotTakeQueuesNothing(): void
    {
        $this->payloadBuilder->method('build')->willReturn(null);

        $this->ingest()->enqueue($this->order(), true);

        self::assertSame([], $this->inserted);
    }

    /**
     * @param array<string, mixed> ...$payloads
     */
    private function builds(array ...$payloads): void
    {
        $this->payloadBuilder->method('build')->willReturnOnConsecutiveCalls(...array_values($payloads));
    }

    private function ingest(): OrderIngest
    {
        return new OrderIngest($this->payloadBuilder, $this->queue);
    }

    private function order(string $incrementId = '100000042'): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);

        return $order;
    }
}
