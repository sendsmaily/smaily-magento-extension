<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Newsletter\Model\Subscriber;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Automation\Trigger;

/**
 * Real-time newsletter subscription sync (newsletter_subscriber_save_after).
 *
 * Fires in every contact-sync mode: subscribing via the newsletter form (or
 * the checkout checkbox, which creates a native subscriber) is an explicit
 * opt-in act. Pending double-opt-in confirmations are not synced until
 * confirmed. Reconcile writes are suppressed via the guard so Smaily-origin
 * changes never echo back.
 *
 * The welcome automation fires only for a subscription the shopper makes on
 * the storefront (frontend area, or the checkout opt-in, which Luma's
 * checkout saves through the REST API), a resubscription included. A
 * subscription made in the admin, through the REST/SOAP/GraphQL API or by an
 * import (cron or command line) syncs the contact but fires no welcome
 * (PRO-3580).
 */
class SubscriberSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly ReconcileGuard $guard,
        private readonly SyncDispatcher $dispatcher,
        private readonly SubscriberPayloadBuilder $payloadBuilder,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly State $appState,
        private readonly StorefrontSubscription $storefrontSubscription
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber || $this->guard->isApplying()) {
            return;
        }

        $storeId = (int)$subscriber->getStoreId();
        $websiteId = $this->dispatcher->websiteId($storeId);
        if (!$this->config->isSyncEnabled($websiteId)
            || !$this->config->isConnected($storeId)
            || !$subscriber->isStatusChanged()
        ) {
            return;
        }

        $status = (int)$subscriber->getStatus();
        if (!in_array($status, [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED], true)) {
            return;
        }

        $email = (string)$subscriber->getEmail();
        if ($email === '') {
            return;
        }

        $customer = $this->loadCustomer((int)$subscriber->getCustomerId());
        $isUnsubscribed = $status !== Subscriber::STATUS_SUBSCRIBED;

        $this->dispatcher->dispatchContactSync($email, $storeId, $isUnsubscribed, $customer);

        if (!$isUnsubscribed && $this->isStorefront() && $this->config->isWelcomeEnabled($websiteId)) {
            $address = $this->payloadBuilder->build($email, $storeId, null, $customer);
            unset($address['language']);
            $this->dispatcher->dispatchAutomation(Trigger::WELCOME, $storeId, $address);
        }
    }

    private function isStorefront(): bool
    {
        if ($this->storefrontSubscription->isActive()) {
            return true;
        }
        try {
            return $this->appState->getAreaCode() === Area::AREA_FRONTEND;
        } catch (LocalizedException) {
            return false;
        }
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
