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
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\StateManager;
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

    private function observer(
        SubscriptionManagerInterface $subscriptionManager,
        StorefrontSubscription $storefrontSubscription,
        string $mode = SyncMode::MODE_CONSENT,
        bool $optedIn = true,
        ?SyncDispatcher $dispatcher = null
    ): OrderPlaced {
        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $config->method('isSyncEnabled')->willReturn(true);
        $config->method('getSyncMode')->willReturn($mode);
        $config->method('includeGuests')->willReturn(true);

        $stateManager = $this->createMock(StateManager::class);
        $stateManager->method('rowForQuote')->willReturn(['status' => '', 'newsletter_optin' => $optedIn]);

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
            $storefrontSubscription
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
