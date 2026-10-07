<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;

class OrderPayloadBuilderTest extends TestCase
{
    private OrderPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = $this->builderWithReturns([]);
    }

    /**
     * Contract v1.4.0 amount semantics: every money field is GROSS
     * (tax-inclusive) — line basis row_total_incl_tax, total_amount is the
     * grand total charged — and the sender invariant
     * Σ line_total + shipping ≈ total_amount holds.
     */
    public function testAmountsAreGrossAndSenderInvariantHolds(): void
    {
        $shippingInclTax = 5.90;
        $order = $this->order([
            // [sku, qty, rowTotalInclTax, discount]
            ['POC-CAT', 1, 22.99, 0.0],
            ['POC-DENT', 2, 44.51, 5.00],
        ], 22.99 + (44.51 - 5.00) + $shippingInclTax);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertSame(68.40, $payload['total_amount']);
        self::assertSame(5.0, $payload['discount_amount']);

        [$first, $second] = $payload['items'];
        self::assertSame(22.99, $first['unit_price']);
        self::assertSame(22.99, $first['line_total']);
        self::assertArrayNotHasKey('discount_amount', $first);
        // Gross unit price = gross (pre-discount) line total / qty.
        self::assertSame(22.255, $second['unit_price']);
        // Gross line total after line-level discount.
        self::assertSame(39.51, $second['line_total']);
        self::assertSame(5.0, $second['discount_amount']);

        $lineSum = array_sum(array_column($payload['items'], 'line_total'));
        self::assertEqualsWithDelta($payload['total_amount'], $lineSum + $shippingInclTax, 0.01);
    }

    public function testChildRowsOfConfigurablesAreSkipped(): void
    {
        $parent = $this->item('CONF-1', 1, 10.00, 0.0);
        $child = $this->item('CONF-1-S', 1, 10.00, 0.0);
        $child->method('getParentItemId')->willReturn(7);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$parent, $child]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertCount(1, $payload['items']);
        self::assertSame('CONF-1', $payload['items'][0]['sku']);
    }

    public function testTransientStateIsNotRepresentable(): void
    {
        $order = $this->order([], 1.0, Order::STATE_HOLDED);

        self::assertNull($this->builder->build($order));
    }

    /**
     * Symmetric mag-<entity_id> fallback (PRO-1280): an empty order-line SKU
     * must key on `mag-<product_id>` — the same token the catalog builder emits
     * for the same empty-SKU product (`mag-<entity_id>`, and
     * `sales_order_item.product_id` IS that catalog entity_id) — so catalog and
     * order rows join instead of diverging onto `mag-...` vs `""`.
     */
    public function testEmptySkuOrderLineFallsBackToMagProductId(): void
    {
        $item = $this->item('', 1, 10.00, 0.0);
        $item->method('getProductId')->willReturn(42);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$item]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertCount(1, $payload['items']);
        // Matches CatalogPayloadBuilder::sku()'s 'mag-' . (int)$product->getId().
        self::assertSame('mag-42', $payload['items'][0]['sku']);
    }

    /**
     * A whitespace-only SKU is also treated as empty and falls back, mirroring
     * the catalog builder's trim() before the emptiness check.
     */
    public function testWhitespaceOnlySkuOrderLineFallsBackToMagProductId(): void
    {
        $item = $this->item('   ', 1, 10.00, 0.0);
        $item->method('getProductId')->willReturn(7);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$item]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertSame('mag-7', $payload['items'][0]['sku']);
    }

    /**
     * PRO-3715: an empty-SKU configurable line names the variant bought —
     * `mag-<child product id>`, the key of the variant's own catalog row —
     * not `mag-<parent id>`.
     */
    public function testEmptySkuConfigurableLineKeysOnTheVariantBought(): void
    {
        $parent = $this->item('', 1, 10.00, 0.0);
        $parent->method('getItemId')->willReturn(7);
        $parent->method('getProductId')->willReturn(17);
        $parent->method('getProductType')->willReturn('configurable');
        $child = $this->item('', 1, 10.00, 0.0);
        $child->method('getParentItemId')->willReturn(7);
        $child->method('getProductId')->willReturn(42);
        $child->method('getProductType')->willReturn('simple');

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$parent, $child]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertCount(1, $payload['items']);
        self::assertSame('mag-42', $payload['items'][0]['sku']);
    }

    /**
     * PRO-3715: only a configurable line keys on its child — an empty-SKU
     * bundle line keeps its own product id (the bundle's catalog row).
     */
    public function testEmptySkuBundleLineKeepsItsOwnProductId(): void
    {
        $parent = $this->item('', 1, 10.00, 0.0);
        $parent->method('getItemId')->willReturn(7);
        $parent->method('getProductId')->willReturn(17);
        $parent->method('getProductType')->willReturn('bundle');
        $child = $this->item('', 1, 0.0, 0.0);
        $child->method('getParentItemId')->willReturn(7);
        $child->method('getProductId')->willReturn(42);

        $order = $this->order([], 10.00);
        $order->method('getItems')->willReturn([$parent, $child]);

        $payload = $this->builder->build($order);

        self::assertNotNull($payload);
        self::assertSame('mag-17', $payload['items'][0]['sku']);
    }

    /**
     * Contract §5 (v1.8.0) return signals: a Magento PARTIAL refund never
     * changes the order state, so the credit memos are the only record — and
     * they are re-read on every build, because the engine replaces an order's
     * items wholesale and a sync that omits returned_at erases the return.
     */
    public function testAFullyCreditedLineCarriesReturnedAtWhileTheRestStaysKept(): void
    {
        $returned = $this->item('POC-CAT', 1, 22.99, 0.0);
        $returned->method('getItemId')->willReturn(11);
        $kept = $this->item('POC-DENT', 2, 44.51, 0.0);
        $kept->method('getItemId')->willReturn(12);

        $order = $this->order([], 67.50, Order::STATE_COMPLETE, 5, 22.99);
        $order->method('getItems')->willReturn([$returned, $kept]);

        $builder = $this->builderWithReturns([
            [11, 1.0, '2026-07-02 09:00:00'],
        ]);

        $items = $builder->build($order)['items'];

        self::assertSame('2026-07-02T09:00:00Z', $items[0]['returned_at']);
        self::assertArrayNotHasKey('returned_at', $items[1], 'a partial refund is per line');
        // §5: neither reason field is guessed — Magento has no return taxonomy.
        self::assertArrayNotHasKey('return_reason_standardised', $items[0]);
        self::assertArrayNotHasKey('return_reason_raw', $items[0]);
    }

    /**
     * §5: a return is whole-line, not per-unit — 1 of 3 credited stays KEPT,
     * and the memo that COMPLETES the line dates the return.
     */
    public function testAPartiallyCreditedQuantityStaysKeptUntilTheLineIsWhole(): void
    {
        $item = $this->item('POC-CAT', 3, 60.00, 0.0);
        $item->method('getItemId')->willReturn(11);

        $order = $this->order([], 60.00, Order::STATE_COMPLETE, 5, 20.00);
        $order->method('getItems')->willReturn([$item]);

        $partial = $this->builderWithReturns([
            [11, 1.0, '2026-07-02 09:00:00'],
        ])->build($order);
        self::assertArrayNotHasKey('returned_at', $partial['items'][0]);

        $whole = $this->builderWithReturns([
            [11, 1.0, '2026-07-02 09:00:00'],
            [11, 2.0, '2026-07-09 15:30:00'],
        ])->build($order);
        self::assertSame('2026-07-09T15:30:00Z', $whole['items'][0]['returned_at']);
    }

    /**
     * PRO-3798: only a refunded credit memo marks its lines returned. A
     * canceled one gave nothing back and a pending (open) one has not
     * refunded yet; neither marks a line, nor adds to a line's quantity.
     */
    public function testOnlyARefundedCreditMemoMarksItsLinesReturned(): void
    {
        $refunded = $this->item('POC-CAT', 1, 22.99, 0.0);
        $refunded->method('getItemId')->willReturn(11);
        $canceled = $this->item('POC-DENT', 1, 44.51, 0.0);
        $canceled->method('getItemId')->willReturn(12);
        $pending = $this->item('POC-BONE', 2, 10.00, 0.0);
        $pending->method('getItemId')->willReturn(13);

        $order = $this->order([], 77.50, Order::STATE_COMPLETE, 5, 22.99);
        $order->method('getItems')->willReturn([$refunded, $canceled, $pending]);

        $items = $this->builderWithReturns([
            [11, 1.0, '2026-07-02 09:00:00', Creditmemo::STATE_REFUNDED],
            [12, 1.0, '2026-07-03 09:00:00', Creditmemo::STATE_CANCELED],
            [13, 1.0, '2026-07-04 09:00:00', Creditmemo::STATE_REFUNDED],
            [13, 1.0, '2026-07-05 09:00:00', Creditmemo::STATE_OPEN],
        ])->build($order)['items'];

        self::assertSame('2026-07-02T09:00:00Z', $items[0]['returned_at']);
        self::assertArrayNotHasKey('returned_at', $items[1], 'a canceled credit memo returns nothing');
        self::assertArrayNotHasKey('returned_at', $items[2], 'a pending credit memo does not complete the line');
    }

    /**
     * An order no credit memo has ever touched (total_refunded IS NULL) never
     * runs the credit-memo query at all.
     */
    public function testAnOrderThatWasNeverRefundedSkipsTheCreditMemoQuery(): void
    {
        $item = $this->item('POC-CAT', 1, 22.99, 0.0);
        $item->method('getItemId')->willReturn(11);

        $order = $this->order([], 22.99, Order::STATE_COMPLETE, 5);
        $order->method('getItems')->willReturn([$item]);

        $payload = $this->builderWithReturns([[11, 1.0, '2026-07-02 09:00:00']])->build($order);

        self::assertArrayNotHasKey('returned_at', $payload['items'][0]);
    }

    /**
     * Contract §5: the engine validates smaily_rec_id as a UUID and rejects
     * the WHOLE order over a malformed one (PRO-3576). A malformed id is left
     * off; the order and its other attribution signals still go.
     *
     * @dataProvider malformedRecIds
     */
    public function testAMalformedRecIdIsLeftOffAndTheOrderStillGoes(string $recId): void
    {
        $order = $this->order([['POC-CAT', 1, 22.99, 0.0]], 22.99, Order::STATE_COMPLETE, 5);

        $payload = $this->builderWithReturns([], [
            'rec_id' => $recId,
            'visitor_token' => 'vt_abc123',
            'rec_ctx' => 'welcome',
            'anon_session_id' => '0f8e3c1a-5b2d-4e6f-8a9b-1c2d3e4f5a6b',
        ])->build($order);

        self::assertNotNull($payload);
        self::assertArrayNotHasKey('smaily_rec_id', $payload);
        self::assertSame('vt_abc123', $payload['smaily_visitor_token']);
        self::assertSame('welcome', $payload['smaily_rec_ctx']);
        self::assertSame('0f8e3c1a-5b2d-4e6f-8a9b-1c2d3e4f5a6b', $payload['session_id']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedRecIds(): array
    {
        return [
            'placeholder' => ['rec_abc123'],
            'truncated' => ['3fa85f64-5717-4562-b3fc-2c963f66af'],
            'surrounding text' => [' 3fa85f64-5717-4562-b3fc-2c963f66afa6x'],
            'non-hex' => ['3fa85f64-5717-4562-b3fc-2c963f66afaz'],
            'no dashes' => ['3fa85f6457174562b3fc2c963f66afa6'],
            'trailing newline' => ["3fa85f64-5717-4562-b3fc-2c963f66afa6\n"],
        ];
    }

    public function testAWellFormedRecIdTravelsWithTheOrder(): void
    {
        $order = $this->order([['POC-CAT', 1, 22.99, 0.0]], 22.99, Order::STATE_COMPLETE, 5);

        $payload = $this->builderWithReturns([], [
            'rec_id' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'visitor_token' => null,
            'rec_ctx' => null,
            'anon_session_id' => null,
        ])->build($order);

        self::assertSame('3fa85f64-5717-4562-b3fc-2c963f66afa6', $payload['smaily_rec_id']);
    }

    /**
     * PRO-3584: a malformed visitor token, context or session id is left off
     * on its own; the order keeps every other well-formed signal. A row
     * stored before the capture checked the shape still goes out clean.
     *
     * @dataProvider malformedSignals
     */
    public function testAMalformedAttributionSignalIsLeftOffOnItsOwn(string $column, string $value, string $key): void
    {
        $order = $this->order([['POC-CAT', 1, 22.99, 0.0]], 22.99, Order::STATE_COMPLETE, 5);
        $row = [
            'rec_id' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'visitor_token' => 'vt_abc123',
            'rec_ctx' => 'welcome',
            'anon_session_id' => '0f8e3c1a-5b2d-4e6f-8a9b-1c2d3e4f5a6b',
        ];
        $row[$column] = $value;

        $payload = $this->builderWithReturns([], $row)->build($order);

        $expected = [
            'smaily_rec_id' => '3fa85f64-5717-4562-b3fc-2c963f66afa6',
            'smaily_visitor_token' => 'vt_abc123',
            'smaily_rec_ctx' => 'welcome',
            'session_id' => '0f8e3c1a-5b2d-4e6f-8a9b-1c2d3e4f5a6b',
        ];
        unset($expected[$key]);
        self::assertSame($expected, array_intersect_key($payload, [
            'smaily_rec_id' => true,
            'smaily_visitor_token' => true,
            'smaily_rec_ctx' => true,
            'session_id' => true,
        ]));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function malformedSignals(): array
    {
        return [
            'visitor token without the vt_ prefix' => ['visitor_token', 'abc123', 'smaily_visitor_token'],
            'visitor token with a space' => ['visitor_token', 'vt_abc 123', 'smaily_visitor_token'],
            'visitor token over 64 characters' => [
                'visitor_token', 'vt_' . str_repeat('a', 62), 'smaily_visitor_token',
            ],
            'visitor token with a trailing newline' => ['visitor_token', "vt_abc123\n", 'smaily_visitor_token'],
            'vs_ visitor token of 21 characters' => [
                'visitor_token', 'vs_4fK9a2LmQ7xZ0bR3tY8wP', 'smaily_visitor_token',
            ],
            'vs_ visitor token with a dash' => [
                'visitor_token', 'vs_4fK9a2LmQ7xZ0bR3tY8w-1', 'smaily_visitor_token',
            ],
            'context with a slash' => ['rec_ctx', 'cross/sell', 'smaily_rec_ctx'],
            'context over 64 characters' => ['rec_ctx', str_repeat('c', 65), 'smaily_rec_ctx'],
            'session id with a quote' => ['anon_session_id', 'sess"1', 'session_id'],
            'session id over 64 characters' => ['anon_session_id', str_repeat('b', 65), 'session_id'],
        ];
    }

    /**
     * PRO-3584: well-formed values travel as before, at the 64-character
     * bound too.
     */
    public function testWellFormedAttributionSignalsTravelAsBefore(): void
    {
        $order = $this->order([['POC-CAT', 1, 22.99, 0.0]], 22.99, Order::STATE_COMPLETE, 5);

        $payload = $this->builderWithReturns([], [
            'rec_id' => null,
            'visitor_token' => 'vt_' . str_repeat('A', 61),
            'rec_ctx' => 'cart_abandoned.v2-' . str_repeat('x', 46),
            'anon_session_id' => 'wp_sess_abc123',
        ])->build($order);

        self::assertSame('vt_' . str_repeat('A', 61), $payload['smaily_visitor_token']);
        self::assertSame('cart_abandoned.v2-' . str_repeat('x', 46), $payload['smaily_rec_ctx']);
        self::assertSame('wp_sess_abc123', $payload['session_id']);
    }

    /**
     * PRO-3912: a visitor token of either form the contract allows, an
     * engine `vt_` token or a store-created `vs_` token, travels unchanged.
     *
     * @dataProvider visitorTokens
     */
    public function testAVisitorTokenOfEitherFormTravelsUnchanged(string $token): void
    {
        $order = $this->order([['POC-CAT', 1, 22.99, 0.0]], 22.99, Order::STATE_COMPLETE, 5);

        $payload = $this->builderWithReturns([], [
            'rec_id' => null,
            'visitor_token' => $token,
            'rec_ctx' => null,
            'anon_session_id' => null,
        ])->build($order);

        self::assertSame($token, $payload['smaily_visitor_token']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function visitorTokens(): array
    {
        return [
            'engine vt_ token' => ['vt_8f3k2a'],
            'store-created vs_ token' => ['vs_4fK9a2LmQ7xZ0bR3tY8wP1'],
        ];
    }

    /**
     * Credit-memo item rows as the join returns them, oldest memo first.
     *
     * @param array<int, array{0: int, 1: float, 2: string, 3?: int}> $returns
     *     [order_item_id, qty, credit-memo created_at, credit-memo state
     *     (refunded when left out)]
     * @param array<string, ?string>|false $attribution the side-table row
     */
    private function builderWithReturns(array $returns, array|false $attribution = false): OrderPayloadBuilder
    {
        $rows = array_map(
            static fn (array $row) => [
                'order_item_id' => $row[0],
                'qty' => $row[1],
                'created_at' => $row[2],
                'state' => (string)($row[3] ?? Creditmemo::STATE_REFUNDED),
            ],
            $returns
        );

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);
        // false = no row in the attribution side table: no attribution keys.
        $connection->method('fetchRow')->willReturn($attribution);

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        return new OrderPayloadBuilder($resourceConnection);
    }

    /**
     * @param array<int, array{0: string, 1: int, 2: float, 3: float}> $items
     * @return \PHPUnit\Framework\MockObject\MockObject&OrderInterface
     */
    private function order(
        array $items,
        float $grandTotal,
        string $state = Order::STATE_COMPLETE,
        int $entityId = 0,
        ?float $totalRefunded = null
    ) {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getState')->willReturn($state);
        $order->method('getCustomerEmail')->willReturn('Mari@Example.com ');
        $order->method('getCreatedAt')->willReturn('2026-07-01 10:00:00');
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getDiscountAmount')->willReturn(
            -array_sum(array_column($items, 3))
        );
        // entity_id 0 short-circuits the attribution lookup; a NULL
        // total_refunded short-circuits the credit-memo one.
        $order->method('getEntityId')->willReturn($entityId);
        $order->method('getTotalRefunded')->willReturn($totalRefunded);

        if ($items !== []) {
            $order->method('getItems')->willReturn(array_map(
                fn (array $row) => $this->item(...$row),
                $items
            ));
        }

        return $order;
    }

    /**
     * @return \PHPUnit\Framework\MockObject\MockObject&OrderItemInterface
     */
    private function item(string $sku, int $qty, float $rowTotalInclTax, float $discount)
    {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getQtyOrdered')->willReturn((float)$qty);
        $item->method('getRowTotalInclTax')->willReturn($rowTotalInclTax);
        $item->method('getDiscountAmount')->willReturn($discount);

        return $item;
    }
}
