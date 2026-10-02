<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order;
use Smaily\Connect\Model\Engine\AttributionShape;
use Smaily\Connect\Model\Engine\RecId;

/**
 * Order -> WireOrder (contract §5).
 *
 * Status maps onto the closed engine enum; orders in transient states
 * (payment review, pending payment, hold) are never sent — build() returns
 * null for them. All money fields are GROSS (tax-inclusive) order-currency
 * values per contract v1.4.0 amount semantics: row_total_incl_tax for
 * lines, grand_total for total_amount; items carry pre-discount unit
 * prices and post-discount line totals. Attribution fields come
 * from the smaily_order_attribution side table; a malformed rec id
 * (Engine\RecId), visitor token, context or session id
 * (Engine\AttributionShape) is omitted on its own.
 *
 * Return signals (contract §5, v1.8.0) are derived from the order's OWN
 * credit memos on every build, never from a one-shot event payload: the
 * engine fully replaces an order's items on re-ingest, so a later sync that
 * omits returned_at ERASES the return. Deriving here means the live observer,
 * a flusher retry and the order backfill all re-send it for free.
 */
class OrderPayloadBuilder
{
    private const STATUS_MAP = [
        Order::STATE_COMPLETE => 'completed',
        Order::STATE_NEW => 'processing',
        Order::STATE_PROCESSING => 'processing',
        Order::STATE_CANCELED => 'cancelled',
        Order::STATE_CLOSED => 'refunded',
    ];

    private const ATTRIBUTION_TABLE = 'smaily_order_attribution';

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * @return array<string, mixed>|null null when the order state is not
     *     representable in the engine enum (skip, do not queue)
     */
    public function build(OrderInterface $order): ?array
    {
        $status = self::STATUS_MAP[(string)$order->getState()] ?? null;
        $email = strtolower(trim((string)$order->getCustomerEmail()));
        if ($status === null || $email === '') {
            return null;
        }

        $item = [
            'external_order_id' => (string)$order->getIncrementId(),
            'customer_email' => $email,
            'ordered_at' => $this->wireTimestamp((string)$order->getCreatedAt()),
            'total_amount' => round((float)$order->getGrandTotal(), 4),
            'currency' => (string)$order->getOrderCurrencyCode() ?: CatalogPayloadBuilder::DEFAULT_CURRENCY,
            'status' => $status,
            'items' => $this->items($order),
        ];

        $discount = abs((float)$order->getDiscountAmount());
        if ($discount > 0) {
            $item['discount_amount'] = round($discount, 4);
        }

        return array_merge($item, $this->attribution((int)$order->getEntityId()));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(OrderInterface $order): array
    {
        $returns = $this->returnsByOrderItem($order);
        $items = [];
        foreach ($order->getItems() as $orderItem) {
            // Product rows only: children of configurables carry the price on
            // the parent row, so skip items with a parent to avoid doubling.
            if ($orderItem->getParentItemId()) {
                continue;
            }

            $qty = (float)$orderItem->getQtyOrdered();
            if ($qty <= 0) {
                continue;
            }

            $rowTotalInclTax = (float)($orderItem->getRowTotalInclTax() ?? $orderItem->getRowTotal());
            $itemDiscount = abs((float)$orderItem->getDiscountAmount());

            $row = [
                'sku' => $this->sku($orderItem),
                // Integer when whole (contract examples use ints); a genuinely
                // fractional qty (e.g. 1.5 kg) wires as a float.
                'qty' => $qty == (int)$qty ? (int)$qty : $qty,
                'unit_price' => round($rowTotalInclTax / $qty, 4),
                'line_total' => round(max(0, $rowTotalInclTax - $itemDiscount), 4),
            ];
            if ($itemDiscount > 0) {
                $row['discount_amount'] = round($itemDiscount, 4);
            }

            // §5: returned_at marks the whole LINE — a partially refunded
            // quantity stays KEPT, because the customer still owns the rest.
            // Neither reason field is sent: Magento has no structured return
            // taxonomy anywhere, and §5 says guessing one is worse than
            // sending nothing.
            $returned = $returns[(int)$orderItem->getItemId()] ?? null;
            if ($returned !== null && $returned['qty'] >= $qty) {
                $row['returned_at'] = $this->wireTimestamp($returned['created_at']);
            }

            $items[] = $row;
        }

        return $items;
    }

    /**
     * The order's credit memos, collapsed per order line.
     *
     * A Magento PARTIAL refund leaves the order state alone (only a full
     * refund closes it, which the engine already derives from
     * `status: refunded`), so the credit memos are the only record of a
     * line-level return — and they are read fresh on every build so a
     * re-sync can never erase a return the engine already has.
     *
     * Quantities accumulate across several credit memos for the same line;
     * rows come oldest-first, so the last memo to touch a line — the one that
     * completed it — dates the return.
     *
     * @return array<int, array{qty: float, created_at: string}> keyed by order-item id
     */
    private function returnsByOrderItem(OrderInterface $order): array
    {
        // A NULL total_refunded means no credit memo has ever touched this
        // order, so the overwhelming majority of orders skip the query. A
        // 0.00 total is NOT the same thing: a zero-value credit memo still
        // carries returned lines, and dropping them would erase a return the
        // engine already has on the next re-sync (PRO-1955).
        if ($order->getTotalRefunded() === null) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                ['ci' => $this->resourceConnection->getTableName('sales_creditmemo_item', 'sales')],
                ['order_item_id', 'qty']
            )
            ->join(
                ['c' => $this->resourceConnection->getTableName('sales_creditmemo', 'sales')],
                'c.entity_id = ci.parent_id',
                ['created_at']
            )
            ->where('c.order_id = ?', (int)$order->getEntityId())
            ->order('c.created_at ASC');

        $returns = [];
        foreach ($connection->fetchAll($select) as $row) {
            $orderItemId = (int)$row['order_item_id'];
            $qty = (float)$row['qty'];
            if ($orderItemId <= 0 || $qty <= 0) {
                continue;
            }
            $returns[$orderItemId] = [
                'qty' => ($returns[$orderItemId]['qty'] ?? 0.0) + $qty,
                'created_at' => (string)$row['created_at'],
            ];
        }

        return $returns;
    }

    /**
     * A Magento DB datetime (always UTC) as a contract wire timestamp.
     */
    private function wireTimestamp(string $datetime): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', strtotime($datetime) ?: time());
    }

    /**
     * The order-line identity key, symmetric with the catalog builder.
     *
     * Magento's catalog `sku` field IS the platform-canonical key (mandatory +
     * store-unique), so it is normally emitted verbatim. When the SKU field is
     * empty — pathological, but possible — the catalog builder keys the row on
     * `mag-<entity_id>` (CatalogPayloadBuilder::sku). The order item's
     * `product_id` (`sales_order_item.product_id`) IS that same catalog
     * `entity_id`, so an empty order-line SKU must key the SAME way, or the
     * catalog row (`mag-<entity_id>`) and the order line (`""`) diverge onto
     * different keys and never join — breaking attribution + cadence, and an
     * empty `sku` is a cross-product collision magnet (contract §3, "Same key
     * from every path" / catalog↔order-line fallback symmetry, PRO-1280).
     */
    private function sku(OrderItemInterface $orderItem): string
    {
        $sku = trim((string)$orderItem->getSku());

        return $sku !== '' ? $sku : 'mag-' . (int)$orderItem->getProductId();
    }

    /**
     * @return array<string, string>
     */
    private function attribution(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $select = $connection->select()
            ->from(
                $this->resourceConnection->getTableName(self::ATTRIBUTION_TABLE, 'sales'),
                ['rec_id', 'visitor_token', 'rec_ctx', 'anon_session_id']
            )
            ->where('order_id = ?', $orderId);
        $row = $connection->fetchRow($select);
        if (!is_array($row)) {
            return [];
        }

        $attribution = [];
        // Contract §5: a malformed smaily_rec_id makes the engine reject the
        // whole order, so it is left off and the order goes without it
        // (PRO-3576). The stored row is not rewritten.
        if (RecId::isValid((string)$row['rec_id'])) {
            $attribution['smaily_rec_id'] = (string)$row['rec_id'];
        }
        // The other three are shape-checked the same way: an off-shape value
        // is left off on its own (PRO-3584).
        if (AttributionShape::isVisitorToken((string)$row['visitor_token'])) {
            $attribution['smaily_visitor_token'] = (string)$row['visitor_token'];
        }
        if (AttributionShape::isContext((string)$row['rec_ctx'])) {
            $attribution['smaily_rec_ctx'] = (string)$row['rec_ctx'];
        }
        if (AttributionShape::isSessionId((string)$row['anon_session_id'])) {
            $attribution['session_id'] = (string)$row['anon_session_id'];
        }

        return $attribution;
    }
}
