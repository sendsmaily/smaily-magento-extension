<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;

/**
 * Order placement side effects (sales_order_place_after):
 * 1. The quote is no longer an abandoned cart candidate.
 * 2. A cart that WAS reminded gets its purchase marker (and its still-queued
 *    reminder withdrawn), so the follow-up workflow can exit.
 * 3. Checkout newsletter opt-in and guest-email contact sync.
 * 4. First-order automation for a customer's first purchase.
 */
class OrderPlaced implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly SyncDispatcher $dispatcher,
        private readonly StateManager $abandonedCartState,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly SubscriptionManagerInterface $subscriptionManager,
        private readonly StorefrontSubscription $storefrontSubscription,
        private readonly SubscriberFactory $subscriberFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        // One read of the tracker row, BEFORE markCompleted() overwrites the
        // status; an order without a quote reads as the empty state.
        $quoteId = (int)$order->getQuoteId();
        $cartState = ['status' => '', 'newsletter_optin' => false];
        if ($quoteId > 0) {
            $cartState = $this->abandonedCartState->rowForQuote($quoteId);
            $this->abandonedCartState->markCompleted($quoteId);
        }
        $wasReminded = $cartState['status'] === StateManager::STATUS_MAILED;
        $optedIn = $cartState['newsletter_optin'];

        $storeId = (int)$order->getStoreId();
        if (!$this->config->isConnected($storeId ?: null)) {
            return;
        }

        $websiteId = $this->dispatcher->websiteId($storeId);
        $email = strtolower(trim((string)$order->getCustomerEmail()));
        if ($email === '') {
            return;
        }

        $this->markCartPurchased($email, $storeId, $websiteId, $wasReminded);
        $this->syncContact($order, $email, $storeId, $websiteId, $optedIn);
        $this->triggerFirstOrder($order, $email, $storeId, $websiteId);
    }

    /**
     * The abandoned-cart workflow's exit signal (PRO-2453): a cart the
     * extension tracked as abandoned has converted, so the contact carries
     * the purchase moment and any reminder still queued is withdrawn.
     *
     * A cart that was never tracked sends nothing — marking a shopper the
     * store never emailed would create a contact out of an ordinary
     * purchase. The contact-sync switch is deliberately not consulted:
     * automations run on their own lawful basis, exactly like the marker
     * fields, and this only ever touches a contact already reminded.
     */
    private function markCartPurchased(string $email, int $storeId, int $websiteId, bool $wasReminded): void
    {
        if (!$wasReminded || !$this->config->isAbandonedCartEnabled($websiteId)) {
            return;
        }

        $this->dispatcher->dispatchCartPurchase(
            $email,
            $storeId,
            $this->isUnsubscribedInStore($email, $websiteId)
        );
    }

    private function syncContact(
        OrderInterface $order,
        string $email,
        int $storeId,
        int $websiteId,
        bool $optedIn
    ): void {
        if (!$this->config->isSyncEnabled($websiteId)) {
            return;
        }

        if ($optedIn) {
            // Explicit checkout opt-in: create a native newsletter subscriber.
            // The subscriber-save observer handles the Smaily sync + welcome
            // automation, and double opt-in confirmation is honoured. It is
            // marked as the shopper's own storefront subscription, because
            // Luma's checkout places the order through the REST API
            // (PRO-3580).
            $customerId = (int)$order->getCustomerId();
            $this->storefrontSubscription->run(function () use ($customerId, $email, $storeId): void {
                if ($customerId > 0) {
                    $this->subscriptionManager->subscribeCustomer($customerId, $storeId);
                } else {
                    $this->subscriptionManager->subscribe($email, $storeId);
                }
            });

            return;
        }

        // Guest-email inclusion without explicit opt-in (mode-driven toggle):
        // is_unsubscribed is omitted (soft opt-in) unless the store knows the
        // email unsubscribed — Smaily would create it as a subscriber
        // (PRO-3616). Only a mode that needs no opt-in (legitimate interest)
        // sends it; in the consent and checkout-only modes a guest reaches
        // Smaily only through the opt-in above (PRO-3606).
        $isGuest = (bool)$order->getCustomerIsGuest();
        if ($isGuest && $this->mode->includeGuests($websiteId) && !$this->mode->requiresOptin($websiteId)) {
            $isUnsubscribed = $this->isUnsubscribedInStore($email, $websiteId) ? true : null;
            $this->dispatcher->dispatchContactSync($email, $storeId, $isUnsubscribed, null);
        }
    }

    /**
     * Whether the email's newsletter record on this website — a guest's or a
     * customer's — says it unsubscribed.
     */
    private function isUnsubscribedInStore(string $email, int $websiteId): bool
    {
        $status = (int)$this->subscriberFactory->create()
            ->loadBySubscriberEmail($email, $websiteId)
            ->getStatus();

        return $status === Subscriber::STATUS_UNSUBSCRIBED;
    }

    private function triggerFirstOrder(OrderInterface $order, string $email, int $storeId, int $websiteId): void
    {
        $customerId = (int)$order->getCustomerId();
        if ($customerId <= 0 || !$this->config->isFirstOrderEnabled($websiteId)) {
            return;
        }

        // sales_order_place_after fires before the order row is persisted, so
        // count only OTHER orders (the entity_id filter is a no-op while the
        // current order has no id yet).
        $collection = $this->orderCollectionFactory->create()
            ->addFieldToFilter('customer_id', ['eq' => $customerId]);
        if ($order->getEntityId()) {
            $collection->addFieldToFilter('entity_id', ['neq' => (int)$order->getEntityId()]);
        }
        if ($collection->getSize() > 0) {
            return;
        }

        $address = [
            'email' => $email,
            'is_first_order' => 'true',
            'order_id' => (string)$order->getIncrementId(),
            'order_total' => (string)$order->getGrandTotal(),
            'order_currency' => (string)$order->getOrderCurrencyCode(),
        ];
        $firstname = trim((string)$order->getCustomerFirstname());
        $lastname = trim((string)$order->getCustomerLastname());
        if ($firstname !== '') {
            $address['first_name'] = $firstname;
        }
        if ($lastname !== '') {
            $address['last_name'] = $lastname;
        }
        try {
            $address['store'] = (string)$this->storeManager->getStore($storeId)->getName();
        } catch (LocalizedException) {
            // Store name is decorative in the payload; proceed without it.
        }

        $this->dispatcher->dispatchAutomation(Trigger::FIRST_ORDER, $storeId, $address);
    }
}
