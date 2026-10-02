<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\OrderOrigin;

/**
 * sales_order_place_after: notes whether the order came through the API or
 * through the storefront, for the Storefront URL field (PRO-3660). A failure
 * here is logged and never stops the order.
 */
class RecordOrderOrigin implements ObserverInterface
{
    public function __construct(
        private readonly OrderOrigin $orderOrigin,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        try {
            $this->orderOrigin->record();
        } catch (\Exception $e) {
            $this->logger->error('Could not record where the order came from', ['error' => $e->getMessage()]);
        }
    }
}
