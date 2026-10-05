<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Customer ingest on profile create/update. Separate from Smaily marketing
 * sync: the engine gets every registered customer (no consent fields on the
 * wire); once it confirms one, the flush sends the shopper's stored
 * profiling opt-out again (Cron\FlushIngestQueue, PRO-3760).
 */
class CustomerSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CustomerPayloadBuilder $payloadBuilder,
        private readonly IngestQueue $ingestQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->settings->isConnected()) {
            return;
        }

        $customer = $observer->getEvent()->getData('customer_data_object');
        if (!$customer instanceof CustomerInterface || !$customer->getEmail()) {
            return;
        }

        $this->ingestQueue->enqueue(
            Client::DOMAIN_CUSTOMERS,
            $this->payloadBuilder->build($customer),
            (string)$customer->getId()
        );
    }
}
