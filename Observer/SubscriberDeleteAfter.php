<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Newsletter\Model\Subscriber;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\AdminSubscriberDelete;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;

/**
 * A subscriber row deleted in the admin's Newsletter Subscribers grid
 * unsubscribes the contact in Smaily, as an unsubscribe in the store does
 * (CON-176) — newsletter_subscriber_delete_after. Before, the row went and
 * the contact stayed subscribed in Smaily.
 *
 * Only while the grid's Delete action runs (AdminSubscriberDelete): a
 * customer account's deletion also deletes its subscriber row, and that
 * sends nothing. The unsubscribe's rules apply: nothing goes for a website
 * whose contact sync is not active or a store that is not connected, and a
 * row already unsubscribed sends nothing, as unsubscribing it again changes
 * nothing. The contact sync is queued in the delete's transaction, so a
 * delete that fails queues nothing.
 */
class SubscriberDeleteAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly AdminSubscriberDelete $adminSubscriberDelete,
        private readonly SyncDispatcher $dispatcher,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber
            || !$this->adminSubscriberDelete->isActive()
            || (int)$subscriber->getStatus() === Subscriber::STATUS_UNSUBSCRIBED
        ) {
            return;
        }

        $storeId = (int)$subscriber->getStoreId();
        $email = (string)$subscriber->getEmail();
        if ($email === ''
            || !$this->config->isContactSyncActive($this->dispatcher->websiteId($storeId))
            || !$this->config->isConnected($storeId)
        ) {
            return;
        }

        $customer = $this->loadCustomer((int)$subscriber->getCustomerId());
        $this->dispatcher->dispatchContactSync($email, $storeId, true, $customer);
    }

    private function loadCustomer(int $customerId): ?CustomerInterface
    {
        if ($customerId <= 0) {
            return null;
        }

        try {
            return $this->customerRepository->getById($customerId);
        } catch (LocalizedException) {
            return null;
        }
    }
}
