<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Sales\Model\Order;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;

/**
 * The single place an order turns into an orders ingest row: the order save
 * (Observer\Engine\OrderSaveAfter) and a new credit memo
 * (Plugin\Engine\CreditmemoSave, PRO-1955).
 *
 * A refund saves the credit memo and the order one after the other in one
 * request, in either order: Magento's admin refund saves the credit memo
 * first and closes a fully refunded order only when the order is saved;
 * the REST refunds save the order first, before the credit memo the return
 * signal (contract §5) is read from. So a refund builds the order's row
 * twice, and only the later build is complete. The two collapse into one
 * row: the later build replaces the payload of the row the earlier one
 * queued while that row is still pending and untried
 * (IngestQueue::replacePendingPayload()), and an identical build queues
 * nothing. Only builds next to each other for one order, one of them a
 * credit memo's, collapse; consecutive saves of an order without a refund
 * each queue their row, as before.
 */
class OrderIngest
{
    /** The increment id of the order whose row was queued last in this request. */
    private ?string $lastOrderId = null;

    /** That row's id, null when it was not queued. */
    private ?int $lastRowId = null;

    /** That row's payload as JSON. */
    private ?string $lastPayload = null;

    /** Whether one of the builds that row holds was a credit memo's. */
    private bool $lastWasRefund = false;

    public function __construct(
        private readonly OrderPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * Queue the order's current row; nothing when its state is not one the
     * engine knows (OrderPayloadBuilder::build()).
     *
     * @param bool $refund whether a credit memo of the order was just saved
     */
    public function enqueue(Order $order, bool $refund = false): void
    {
        $item = $this->payloadBuilder->build($order);
        if ($item === null) {
            return;
        }

        $orderId = (string)$order->getIncrementId();
        $payload = (string)json_encode($item);
        $collapse = $orderId === $this->lastOrderId && ($refund || $this->lastWasRefund);
        $this->lastWasRefund = $refund || $collapse;

        if ($collapse && $this->lastRowId !== null) {
            if ($payload === $this->lastPayload) {
                return;
            }
            if ($this->ingestQueue->replacePendingPayload($this->lastRowId, $item)) {
                $this->lastPayload = $payload;

                return;
            }
        }

        $this->lastOrderId = $orderId;
        $this->lastPayload = $payload;
        $this->lastRowId = $this->ingestQueue->enqueueReturningId(Client::DOMAIN_ORDERS, $item, $orderId);
    }
}
