<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Engine;

use Magento\Framework\Model\AbstractModel;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\ResourceModel\Order\Creditmemo as CreditmemoResource;
use Smaily\Connect\Model\Engine\OrderIngest;
use Smaily\Connect\Model\Engine\Settings;

/**
 * A new credit memo → its order's ingest row (PRO-1955). The order payload
 * derives each line's return signal (items[].returned_at, contract §5) from
 * the order's credit memos, but the order save queues the order only when
 * its state or its refunded total moved. A credit memo that moves quantity
 * and no money — the return of a fully discounted line with no adjustment —
 * moves neither, so the return reached the engine only with some later,
 * unrelated save of the order. The credit memo's own save is the refund, so
 * it queues the order, money or not.
 *
 * After the credit memo resource's save, not on sales_order_creditmemo_save_after:
 * that event fires before the credit memo's items are written
 * (VersionControl\AbstractDb::processAfterSaves()), and the return signal is
 * read from them. Every refund path saves the credit memo through it (the
 * repository, the admin refund, the REST refunds, a payment gateway's
 * refund notification).
 *
 * Only a credit memo created by this save — one loaded from the database
 * carries its original data — since a later save of an existing credit memo
 * is no new refund. The same refund's order save queues no second row
 * (OrderIngest).
 */
class CreditmemoSave
{
    public function __construct(
        private readonly Settings $settings,
        private readonly OrderIngest $orderIngest
    ) {
    }

    /**
     * Queue the order of a credit memo this save created.
     *
     * @param CreditmemoResource $subject
     * @param mixed $result
     * @param AbstractModel $creditmemo
     * @return mixed
     */
    public function afterSave(CreditmemoResource $subject, mixed $result, AbstractModel $creditmemo): mixed
    {
        if (!$creditmemo instanceof Creditmemo
            || $creditmemo->getOrigData('entity_id') !== null
            || !$creditmemo->getId()
            || !$this->settings->isConnected()
        ) {
            return $result;
        }

        $order = $creditmemo->getOrder();
        if ($order instanceof Order && $order->getEntityId()) {
            $this->orderIngest->enqueue($order, true);
        }

        return $result;
    }
}
