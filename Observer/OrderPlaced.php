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
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\AbandonedCart\ReminderConsent;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\ContactSync\UnconnectedSignup;

/**
 * Order placement side effects (sales_order_place_after):
 * 1. The quote is no longer an abandoned cart candidate.
 * 2. A cart that WAS reminded gets its purchase marker (and its still-queued
 *    reminder withdrawn), so the follow-up workflow can exit.
 * 3. Checkout newsletter opt-in and guest-email contact sync; on a website
 *    without a Smaily connection, the contact it would have synced is
 *    recorded in the Log as skipped (CON-470, UnconnectedSignup).
 * 4. First-order automation for a customer's first purchase, by the
 *    abandoned-cart reminder's rule (ReminderConsent, CON-387).
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
        private readonly ReminderConsent $consent,
        private readonly UnconnectedSignup $unconnectedSignup
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
            $this->recordUnconnected($order, $storeId, $optedIn);

            return;
        }

        $websiteId = $this->dispatcher->websiteId($storeId);
        $email = strtolower(trim((string)$order->getCustomerEmail()));
        if ($email === '') {
            return;
        }

        $this->markCartPurchased($email, $storeId, $websiteId, $wasReminded);
        $this->syncContact($order, $email, $storeId, $websiteId, $optedIn);
        $this->triggerFirstOrder($order, $email, $storeId, $websiteId, $optedIn);
    }

    /**
     * The contact a website without a Smaily connection would have synced
     * from the order (CON-470): the checkout opt-in, as a subscription, or a
     * guest order's email by the rule of syncContact(). The Log records it
     * as skipped (UnconnectedSignup); nothing else of the order is.
     */
    private function recordUnconnected(OrderInterface $order, int $storeId, bool $optedIn): void
    {
        $websiteId = $this->dispatcher->websiteId($storeId);
        $email = strtolower(trim((string)$order->getCustomerEmail()));
        if ($optedIn) {
            $this->unconnectedSignup->record($email, $storeId, false, null);
        } elseif ($this->syncsGuest($order, $websiteId)) {
            $this->unconnectedSignup->record($email, $storeId, null, null);
        }
    }

    /**
     * Whether a guest order's email syncs without the opt-in: only in a mode
     * that needs no opt-in (legitimate interest) with the guests included.
     */
    private function syncsGuest(OrderInterface $order, int $websiteId): bool
    {
        return (bool)$order->getCustomerIsGuest()
            && $this->mode->includeGuests($websiteId)
            && !$this->mode->requiresOptin($websiteId);
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

        $this->dispatcher->dispatchCartPurchase($email, $storeId);
    }

    private function syncContact(
        OrderInterface $order,
        string $email,
        int $storeId,
        int $websiteId,
        bool $optedIn
    ): void {
        if (!$this->config->isContactSyncActive($websiteId)) {
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

        // Guest-email inclusion without explicit opt-in (mode-driven toggle),
        // only in a mode that needs no opt-in (legitimate interest); in the
        // consent and checkout-only modes a guest reaches Smaily only through
        // the opt-in above (PRO-3606). The order changes no subscription
        // status, so none is sent (CON-196; Erkki, 2026-10-23, superseding
        // PRO-3616 here): Smaily keeps an existing contact's status and
        // creates a missing one as subscribed.
        if ($this->syncsGuest($order, $websiteId)) {
            $this->dispatcher->dispatchContactSync($email, $storeId, null, null);
        }
    }

    /**
     * Who gets the first-order email follows the website's contact-sync mode,
     * as the abandoned-cart reminder does (CON-387): a customer unsubscribed
     * in the store under legitimate interest is recorded in the Log as
     * skipped; every other email is queued, and the queue reads the contact
     * in Smaily before it sends (AutomationHandler::CONTACT_CHECK).
     */
    private function triggerFirstOrder(
        OrderInterface $order,
        string $email,
        int $storeId,
        int $websiteId,
        bool $optedIn
    ): void {
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

        $verdict = $this->consent->verdict($websiteId, $this->storeIds($websiteId), $email, $customerId, $optedIn);
        if ($verdict === ReminderConsent::SKIP_UNSUBSCRIBED) {
            $this->dispatcher->recordSkippedAutomation(
                Trigger::FIRST_ORDER,
                $storeId,
                $address,
                ReminderConsent::SKIPPED_UNSUBSCRIBED_IN_STORE
            );

            return;
        }

        $this->dispatcher->dispatchFirstOrder($storeId, $address, $verdict === ReminderConsent::SEND);
    }

    /**
     * The website's store views, whose newsletter records are the customer's.
     *
     * @return int[]
     */
    private function storeIds(int $websiteId): array
    {
        try {
            $website = $this->storeManager->getWebsite($websiteId);
        } catch (LocalizedException) {
            return [];
        }

        return $website instanceof Website ? array_map('intval', $website->getStoreIds()) : [];
    }
}
