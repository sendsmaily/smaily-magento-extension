<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\ReminderConsent;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Observer\OrderPlaced;

/**
 * PRO-3580: the checkout opt-in is a subscription the shopper made on the
 * storefront, so it is saved as one — also when Luma's checkout places the
 * order through the REST API, where the area alone does not tell.
 *
 * PRO-3606: a guest order's email reaches Smaily without the opt-in only in
 * the all-customers mode.
 *
 * CON-196: an order changes no subscription status, so a guest's email and
 * the purchase marker never carry one (superseding PRO-3616).
 *
 * PRO-4036: before contact sync is active for the website, an order sends
 * no contact and saves no checkout opt-in; the abandoned-cart purchase
 * marker, an automation's, still goes.
 *
 * CON-387: who gets the first-order email follows the abandoned-cart
 * reminder's rule: a customer unsubscribed in the store under legitimate
 * interest is recorded as skipped, every other email is queued with whether
 * an address Smaily does not have may get it.
 */
class OrderPlacedTest extends TestCase
{
    /**
     * @dataProvider customerIdProvider
     */
    public function testTheCheckoutOptInIsSavedAsAStorefrontSubscription(int $customerId, string $method): void
    {
        $storefrontSubscription = new StorefrontSubscription();
        $markedDuringSave = null;
        $subscriptionManager = $this->createMock(SubscriptionManagerInterface::class);
        $subscriptionManager->expects(self::once())->method($method)->willReturnCallback(
            function () use ($storefrontSubscription, &$markedDuringSave) {
                $markedDuringSave = $storefrontSubscription->isActive();

                return $this->createMock(Subscriber::class);
            }
        );

        $this->observer($subscriptionManager, $storefrontSubscription)->execute($this->eventFor($customerId));

        self::assertTrue($markedDuringSave);
        self::assertFalse($storefrontSubscription->isActive());
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function customerIdProvider(): array
    {
        return [
            'guest' => [0, 'subscribe'],
            'customer' => [42, 'subscribeCustomer'],
        ];
    }

    /**
     * PRO-3606: with "Include guest order emails" on, a guest order without
     * the checkout opt-in reaches Smaily only in the all-customers
     * (legitimate interest) mode. In the consent and checkout-opt-in-only
     * modes a guest's email reaches Smaily only with the opt-in.
     *
     * @dataProvider guestWithoutOptInProvider
     */
    public function testAGuestOrderWithoutTheOptInSyncsOnlyUnderLegitimateInterest(string $mode, bool $syncs): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects($syncs ? self::once() : self::never())->method('dispatchContactSync')
            ->with('person@example.com', 1, null, null);
        $subscriptionManager = $this->createMock(SubscriptionManagerInterface::class);
        $subscriptionManager->expects(self::never())->method('subscribe');

        $this->observer($subscriptionManager, new StorefrontSubscription(), $mode, false, $dispatcher)
            ->execute($this->eventFor(0));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function guestWithoutOptInProvider(): array
    {
        return [
            'subscribers only (consent)' => [SyncMode::MODE_CONSENT, false],
            'checkout opt-in only' => [SyncMode::MODE_CHECKOUT_OPTIN, false],
            'all customers (legitimate interest)' => [SyncMode::MODE_LEGITIMATE_INTEREST, true],
        ];
    }

    /**
     * CON-196: under all customers, a guest order's email is sent without a
     * status, also one that unsubscribed in the store — the order changes no
     * subscription status (Erkki, 2026-10-23, superseding PRO-3616). The
     * observer reads no newsletter record for it.
     */
    public function testAGuestOrderUnderAllCustomersCarriesNoStatus(): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, self::identicalTo(null), null);

        $this->observer(
            $this->createMock(SubscriptionManagerInterface::class),
            new StorefrontSubscription(),
            SyncMode::MODE_LEGITIMATE_INTEREST,
            false,
            $dispatcher
        )->execute($this->eventFor(0));
    }

    /**
     * CON-196: the abandoned-cart purchase marker changes another field, so
     * it never carries a subscription status; it goes only to a contact
     * Smaily has (ContactSyncHandler).
     */
    public function testTheCartPurchaseMarkerNeverCarriesAStatus(): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::once())->method('dispatchCartPurchase')
            ->with('person@example.com', 1);

        $this->observer(
            $this->createMock(SubscriptionManagerInterface::class),
            new StorefrontSubscription(),
            SyncMode::MODE_CONSENT,
            false,
            $dispatcher,
            StateManager::STATUS_MAILED
        )->execute($this->eventFor(42));
    }

    /**
     * PRO-4036: an order on a website whose Contacts settings were never
     * saved — an opt-in or a guest email under all customers — queues no
     * contact and subscribes nobody.
     *
     * @dataProvider orderBeforeActiveProvider
     */
    public function testAnOrderQueuesNoContactBeforeContactSyncIsActive(
        string $mode,
        bool $optedIn,
        int $customerId
    ): void {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::never())->method('dispatchContactSync');
        $subscriptionManager = $this->createMock(SubscriptionManagerInterface::class);
        $subscriptionManager->expects(self::never())->method('subscribe');
        $subscriptionManager->expects(self::never())->method('subscribeCustomer');

        $this->observer($subscriptionManager, new StorefrontSubscription(), $mode, $optedIn, $dispatcher, '', false)
            ->execute($this->eventFor($customerId));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: int}>
     */
    public static function orderBeforeActiveProvider(): array
    {
        return [
            'a guest\'s checkout opt-in' => [SyncMode::MODE_CONSENT, true, 0],
            'a customer\'s checkout opt-in' => [SyncMode::MODE_CONSENT, true, 42],
            'a guest email under all customers' => [SyncMode::MODE_LEGITIMATE_INTEREST, false, 0],
        ];
    }

    /**
     * PRO-4036: the abandoned-cart purchase marker is not contact sync, so
     * it goes before contact sync is active too.
     */
    public function testTheCartPurchaseMarkerGoesBeforeContactSyncIsActive(): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::once())->method('dispatchCartPurchase')->with('person@example.com', 1);
        $dispatcher->expects(self::never())->method('dispatchContactSync');

        $this->observer(
            $this->createMock(SubscriptionManagerInterface::class),
            new StorefrontSubscription(),
            SyncMode::MODE_CONSENT,
            false,
            $dispatcher,
            StateManager::STATUS_MAILED,
            false
        )->execute($this->eventFor(42));
    }

    /**
     * @dataProvider firstOrderVerdictProvider
     */
    public function testTheFirstOrderEmailIsQueuedByTheRemindersRule(string $verdict, bool $toNewContact): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::never())->method('dispatchAutomation');
        $dispatcher->expects(self::never())->method('recordSkippedAutomation');
        $dispatcher->expects(self::once())->method('dispatchFirstOrder')->with(
            1,
            self::callback(static fn (array $address): bool => $address['email'] === 'person@example.com'
                && $address['is_first_order'] === 'true'),
            $toNewContact
        );

        $this->firstOrderObserver($dispatcher, $verdict, false)->execute($this->eventFor(42));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function firstOrderVerdictProvider(): array
    {
        return [
            'consent in the store, or legitimate interest' => [ReminderConsent::SEND, true],
            'only as a subscribed contact in Smaily' => [ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT, false],
        ];
    }

    public function testAFirstOrderOfACustomerUnsubscribedInTheStoreIsSkippedAndTheLogSaysWhy(): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::never())->method('dispatchFirstOrder');
        $dispatcher->expects(self::once())->method('recordSkippedAutomation')->with(
            Trigger::FIRST_ORDER,
            1,
            self::callback(static fn (array $address): bool => $address['email'] === 'person@example.com'),
            ReminderConsent::SKIPPED_UNSUBSCRIBED_IN_STORE
        );

        $this->firstOrderObserver($dispatcher, ReminderConsent::SKIP_UNSUBSCRIBED, false)
            ->execute($this->eventFor(42));
    }

    /**
     * The rule weighs the website's store views and the checkout tick on
     * this order, read before the tracker row is closed.
     */
    public function testTheFirstOrderRuleWeighsTheWebsitesStoreViewsAndTheCheckoutTick(): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $consent = $this->createMock(ReminderConsent::class);
        $consent->expects(self::once())->method('verdict')
            ->with(1, [1, 3], 'person@example.com', 42, true)
            ->willReturn(ReminderConsent::SEND);

        $this->firstOrderObserver($dispatcher, ReminderConsent::SEND, true, $consent)->execute($this->eventFor(42));
    }

    public function testALaterOrderSendsNoFirstOrderEmail(): void
    {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->method('websiteId')->willReturn(1);
        $dispatcher->expects(self::never())->method('dispatchFirstOrder');
        $dispatcher->expects(self::never())->method('recordSkippedAutomation');

        $this->firstOrderObserver($dispatcher, ReminderConsent::SEND, false, null, 1)->execute($this->eventFor(42));
    }

    private function firstOrderObserver(
        SyncDispatcher $dispatcher,
        string $verdict,
        bool $optedIn,
        ?ReminderConsent $consent = null,
        int $otherOrders = 0
    ): OrderPlaced {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $config->method('isFirstOrderEnabled')->with(1)->willReturn(true);

        $stateManager = $this->createMock(StateManager::class);
        $stateManager->method('rowForQuote')->willReturn(['status' => '', 'newsletter_optin' => $optedIn]);

        $orders = $this->createMock(OrderCollection::class);
        $orders->method('addFieldToFilter')->willReturnSelf();
        $orders->method('getSize')->willReturn($otherOrders);
        $orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $orderCollectionFactory->method('create')->willReturn($orders);

        $stores = [];
        foreach ([1 => 1, 2 => 2, 3 => 1] as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);
        $storeManager->method('getStore')->willReturn($stores[0]);
        $storeManager->method('getWebsite')->willReturnCallback(function (int $websiteId) use ($stores): Website {
            $website = $this->createMock(Website::class);
            $website->method('getStoreIds')->willReturn(array_map(
                static fn (StoreInterface $store): string => (string)$store->getId(),
                array_values(array_filter(
                    $stores,
                    static fn (StoreInterface $store): bool => (int)$store->getWebsiteId() === $websiteId
                ))
            ));

            return $website;
        });

        if ($consent === null) {
            $consent = $this->createMock(ReminderConsent::class);
            $consent->method('verdict')->willReturn($verdict);
        }

        return new OrderPlaced(
            $config,
            new Mode($config),
            $dispatcher,
            $stateManager,
            $orderCollectionFactory,
            $storeManager,
            $this->createMock(SubscriptionManagerInterface::class),
            new StorefrontSubscription(),
            $consent
        );
    }

    private function observer(
        SubscriptionManagerInterface $subscriptionManager,
        StorefrontSubscription $storefrontSubscription,
        string $mode = SyncMode::MODE_CONSENT,
        bool $optedIn = true,
        ?SyncDispatcher $dispatcher = null,
        string $cartStatus = '',
        bool $active = true
    ): OrderPlaced {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $config->method('isContactSyncActive')->with(1)->willReturn($active);
        $config->method('getSyncMode')->willReturn($mode);
        $config->method('includeGuests')->willReturn(true);
        $config->method('isAbandonedCartEnabled')->willReturn(true);

        $stateManager = $this->createMock(StateManager::class);
        $stateManager->method('rowForQuote')->willReturn(['status' => $cartStatus, 'newsletter_optin' => $optedIn]);

        if ($dispatcher === null) {
            $dispatcher = $this->createMock(SyncDispatcher::class);
            $dispatcher->method('websiteId')->willReturn(1);
        }

        return new OrderPlaced(
            $config,
            new Mode($config),
            $dispatcher,
            $stateManager,
            $this->createMock(OrderCollectionFactory::class),
            $this->createMock(StoreManagerInterface::class),
            $subscriptionManager,
            $storefrontSubscription,
            $this->createMock(ReminderConsent::class)
        );
    }

    private function eventFor(int $customerId): Observer
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getQuoteId')->willReturn(7);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerEmail')->willReturn('person@example.com');
        $order->method('getCustomerId')->willReturn($customerId ?: null);
        $order->method('getCustomerIsGuest')->willReturn($customerId === 0 ? 1 : 0);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('order')->willReturn($order);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
