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
use Smaily\Connect\Model\Engine\CustomerIngest;

/**
 * Customer ingest on a save of the account (customer_save_after_data_object:
 * a registration, the shopper's account page, the admin, the REST and
 * GraphQL APIs). Separate from Smaily marketing sync: no consent fields on
 * the wire; once the engine confirms a customer, the flush sends the
 * shopper's stored profiling opt-out again (Cron\FlushIngestQueue,
 * PRO-3760). Only a customer in the Campaign Intelligence customer scope
 * is queued (Engine\CustomerIngest, CON-266).
 */
class CustomerSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly CustomerIngest $customerIngest
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer_data_object');
        if (!$customer instanceof CustomerInterface) {
            return;
        }

        $this->customerIngest->saved($customer);
    }
}
