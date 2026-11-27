<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\ContactSync\UnconnectedSignup;

/**
 * Keeps Smaily contact fields fresh on customer profile changes
 * (customer_save_after_data_object).
 *
 * Audience follows the contact-sync mode: legitimate interest syncs every
 * registered customer; consent mode only refreshes customers who are
 * subscribed; checkout-only mode never syncs accounts. A profile save changes
 * other fields, so it never sends is_unsubscribed (CON-196; Erkki,
 * 2026-10-23, superseding PRO-3616 here): Smaily keeps an existing contact's
 * status, and creates a missing contact as subscribed — also one who
 * unsubscribed in the store under legitimate interest. A website without a
 * Smaily connection records the sync it would have sent in the Log as
 * skipped (CON-470, UnconnectedSignup).
 */
class CustomerSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly ReconcileGuard $guard,
        private readonly SyncDispatcher $dispatcher,
        private readonly SubscriberFactory $subscriberFactory,
        private readonly UnconnectedSignup $unconnectedSignup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $customer = $observer->getEvent()->getData('customer_data_object');
        if (!$customer instanceof CustomerInterface || $this->guard->isApplying()) {
            return;
        }

        $storeId = (int)$customer->getStoreId();
        $websiteId = (int)$customer->getWebsiteId();
        if (!$this->config->isContactSyncActive($websiteId) || !$this->mode->syncsAccounts($websiteId)) {
            return;
        }
        // A website without a Smaily connection: only a sync the Log records
        // (UnconnectedSignup) needs the subscription read.
        $connected = $this->config->isConnected($storeId ?: null);
        if (!$connected && !$this->unconnectedSignup->applies($storeId)) {
            return;
        }

        $status = (int)$this->subscriberFactory->create()
            ->loadByCustomer((int)$customer->getId(), $websiteId)
            ->getStatus();
        if ($this->mode->requiresOptin($websiteId) && $status !== Subscriber::STATUS_SUBSCRIBED) {
            return;
        }

        if (!$connected) {
            $this->unconnectedSignup->record((string)$customer->getEmail(), $storeId, null, $customer);

            return;
        }

        $this->dispatcher->dispatchContactSync((string)$customer->getEmail(), $storeId, null, $customer);
    }
}
