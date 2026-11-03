<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\CustomerIngest;
use Smaily\Connect\Model\Engine\OrderIngest;
use Smaily\Connect\Model\Engine\Payload\OrderPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Observer\Engine\OrderSaveAfter;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * PRO-1762: the enqueue gate has to notice a refund, not just a state move.
 * A Magento PARTIAL credit memo leaves the order processing/complete, so a
 * state-only gate would never send its per-line return signal (contract §5).
 *
 * CON-266: an order of a registered account puts the buyer in the customer
 * scope's group (b), so a new order, or an order just assigned to an
 * account, offers the buyer to the live customer sync.
 */
class OrderSaveAfterTest extends TestCase
{
    use EngineConnectionsStub;

    private OrderPayloadBuilder&MockObject $payloadBuilder;
    private IngestQueue&MockObject $queue;
    private CustomerIngest&MockObject $customerIngest;

    protected function setUp(): void
    {
        $this->payloadBuilder = $this->createMock(OrderPayloadBuilder::class);
        $this->payloadBuilder->method('build')->willReturn(['external_order_id' => '100000042']);
        $this->queue = $this->createMock(IngestQueue::class);
        $this->customerIngest = $this->createMock(CustomerIngest::class);
    }

    public function testAPartialRefundEnqueuesEvenThoughTheStateDidNotMove(): void
    {
        $this->queue->expects(self::once())->method('enqueueReturningId')
            ->with(Client::DOMAIN_ORDERS, ['external_order_id' => '100000042'], '100000042');

        $this->createObserver()->execute($this->observerFor($this->order(
            Order::STATE_PROCESSING,
            Order::STATE_PROCESSING,
            0.0,
            22.99
        )));
    }

    public function testASaveThatChangesNeitherStateNorRefundedTotalIsANoOp(): void
    {
        $this->queue->expects(self::never())->method('enqueueReturningId');

        $this->createObserver()->execute($this->observerFor($this->order(
            Order::STATE_PROCESSING,
            Order::STATE_PROCESSING,
            22.99,
            22.99
        )));
    }

    public function testAStateChangeStillEnqueues(): void
    {
        $this->queue->expects(self::once())->method('enqueueReturningId');

        $this->createObserver()->execute($this->observerFor($this->order(
            Order::STATE_PROCESSING,
            Order::STATE_COMPLETE,
            0.0,
            0.0
        )));
    }

    public function testANewOrderOfAnAccountOffersTheBuyer(): void
    {
        $this->customerIngest->expects(self::once())->method('joined')->with(77);

        $this->createObserver()->execute($this->observerFor($this->order(null, Order::STATE_NEW, 0.0, 0.0, null, 77)));
    }

    public function testAGuestOrderOffersNobody(): void
    {
        $this->customerIngest->expects(self::never())->method('joined');

        $this->createObserver()->execute($this->observerFor($this->order(null, Order::STATE_NEW, 0.0, 0.0)));
    }

    /**
     * Magento's "create an account" after a guest checkout assigns the
     * order to the new account.
     */
    public function testAGuestOrderAssignedToAnAccountOffersTheBuyer(): void
    {
        $this->customerIngest->expects(self::once())->method('joined')->with(77);

        $this->createObserver()->execute($this->observerFor($this->order(
            Order::STATE_PROCESSING,
            Order::STATE_PROCESSING,
            0.0,
            0.0,
            null,
            77
        )));
    }

    public function testALaterSaveOfTheOrderOffersTheBuyerNoMore(): void
    {
        $this->customerIngest->expects(self::never())->method('joined');

        $this->createObserver()->execute($this->observerFor($this->order(
            Order::STATE_PROCESSING,
            Order::STATE_COMPLETE,
            0.0,
            0.0,
            77,
            77
        )));
    }

    private function createObserver(): OrderSaveAfter
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        return new OrderSaveAfter(
            $settings,
            new OrderIngest($this->payloadBuilder, $this->queue, $this->oneEngineConnection($settings)),
            $this->createMock(AttributionManager::class),
            $this->customerIngest
        );
    }

    private function observerFor(Order $order): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('order')->willReturn($order);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    private function order(
        ?string $origState,
        string $state,
        float $origTotalRefunded,
        float $totalRefunded,
        ?int $origCustomerId = null,
        ?int $customerId = null
    ): Order&MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getState')->willReturn($state);
        $order->method('getTotalRefunded')->willReturn($totalRefunded);
        $order->method('getIncrementId')->willReturn('100000042');
        $order->method('getEntityId')->willReturn(5);
        $order->method('getOrigData')->willReturnCallback(
            static fn (?string $key = null) => [
                'state' => $origState,
                'total_refunded' => $origTotalRefunded,
                'customer_id' => $origCustomerId,
            ][$key] ?? null
        );

        return $order;
    }
}
