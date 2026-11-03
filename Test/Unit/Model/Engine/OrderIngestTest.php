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
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\OrderIngest;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * PRO-1955: a refund saves the credit memo and the order one after the
 * other, in either order, and each save builds the order's row; one refund
 * makes one row, holding the later (complete) build.
 */
class OrderIngestTest extends TestCase
{
    use EngineConnectionsStub;

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

    /** @var list<array{0: string, 1: array<string, mixed>, 2: ?string, 3: ?int}> */
    private array $inserted = [];

    /** @var list<array{0: int, 1: array<string, mixed>}> */
    private array $replaced = [];

    private bool $replaceTakes = true;

    protected function setUp(): void
    {
        $this->payloadBuilder = $this->createMock(OrderPayloadBuilder::class);
        $this->queue = $this->createMock(IngestQueue::class);
        $this->queue->method('enqueueReturningId')->willReturnCallback(
            function (string $domain, array $payload, ?string $entityId = null, ?int $storeId = null): int {
                $this->inserted[] = [$domain, $payload, $entityId, $storeId];

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

        self::assertSame([[Client::DOMAIN_ORDERS, self::RETURNED, '100000042', 1]], $this->inserted);
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

    /**
     * CON-237: separate stores — an order goes to the connection serving
     * the store view it was placed in, with that connection's canonical
     * store view; an order of a store view no connected connection serves,
     * or of one deleted since, queues nothing.
     */
    public function testAnOrderGoesToTheConnectionOfItsStoreView(): void
    {
        $this->payloadBuilder->method('build')->willReturnCallback(
            static fn (Order $order): array => ['external_order_id' => $order->getIncrementId()]
        );
        $connected = $this->connectedSettings();
        $ingest = $this->ingest($this->engineConnections([
            [$this->engineConnection(0, $connected, [1], [1, 2], 1), null],
            [$this->engineConnection(3, $connected, [3], [5, 6], 5), null],
            [$this->engineConnection(4, $this->createMock(Settings::class), [4], [8], 8), null],
        ]));

        $ingest->enqueue($this->order('EE-1', 2));
        $ingest->enqueue($this->order('LV-1', 6));
        $ingest->enqueue($this->order('NOT-CONNECTED', 8));
        $ingest->enqueue($this->order('NO-CONNECTION', 9));
        $ingest->enqueue($this->order('STORE-GONE', null));

        self::assertSame([['EE-1', 1], ['LV-1', 5]], array_map(
            static fn (array $row): array => [$row[2], $row[3]],
            $this->inserted
        ));
    }

    /**
     * CON-237: one store — every order goes to the primary, also one whose
     * store view is gone.
     */
    public function testUnderOneStoreEveryOrderGoesToThePrimary(): void
    {
        $this->builds(self::OPEN, self::CLOSED);

        $this->ingest()->enqueue($this->order('100000042', 3));
        $this->ingest()->enqueue($this->order('100000043', null));

        self::assertSame([1, 1], array_column($this->inserted, 3));
    }

    private function ingest(?Connections $connections = null): OrderIngest
    {
        return new OrderIngest(
            $this->payloadBuilder,
            $this->queue,
            $connections ?? $this->oneEngineConnection($this->connectedSettings())
        );
    }

    private function connectedSettings(): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        return $settings;
    }

    private function order(string $incrementId = '100000042', ?int $storeId = 1): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn($incrementId);
        $order->method('getStoreId')->willReturn($storeId);

        return $order;
    }
}
