<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Controller\Adminhtml\Subscriber\MassDelete;
use Magento\Newsletter\Model\Subscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\AdminSubscriberDelete;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Observer\SubscriberDeleteAfter;
use Smaily\Connect\Plugin\Adminhtml\MarkSubscriberMassDelete;

/**
 * CON-176: a subscriber row deleted in the admin's Newsletter Subscribers
 * grid unsubscribes the contact in Smaily, through the same contact sync an
 * unsubscribe in the store queues; a delete outside the grid's Delete action
 * (a customer account's deletion) sends nothing.
 */
class SubscriberDeleteAfterTest extends TestCase
{
    private SyncDispatcher&MockObject $dispatcher;

    private AdminSubscriberDelete $mark;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(SyncDispatcher::class);
        $this->dispatcher->method('websiteId')->willReturn(1);
        $this->mark = new AdminSubscriberDelete();
    }

    public function testAGuestDeletedInTheGridIsUnsubscribed(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 3, true, null);

        $this->deleteInTheGrid($this->observer(), $this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    public function testACustomerDeletedInTheGridIsUnsubscribedWithTheCustomersFields(): void
    {
        $customer = $this->createMock(CustomerInterface::class);
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($customer);
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 3, true, $customer);

        $this->deleteInTheGrid(
            $this->observer(true, $repository),
            $this->eventFor(Subscriber::STATUS_SUBSCRIBED, 7)
        );
    }

    public function testAPendingSubscriberDeletedInTheGridIsUnsubscribed(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 3, true, null);

        $this->deleteInTheGrid($this->observer(), $this->eventFor(Subscriber::STATUS_NOT_ACTIVE));
    }

    public function testARowAlreadyUnsubscribedSendsNothing(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');

        $this->deleteInTheGrid($this->observer(), $this->eventFor(Subscriber::STATUS_UNSUBSCRIBED));
    }

    public function testNothingIsSentForAWebsiteWhoseContactSyncIsNotActive(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');

        $this->deleteInTheGrid($this->observer(false), $this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    public function testADeleteOutsideTheGridsDeleteActionSendsNothing(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, 7));
    }

    public function testTheMarkEndsWithTheGridsDeleteAction(): void
    {
        $plugin = new MarkSubscriberMassDelete($this->mark);

        $result = $plugin->aroundExecute(
            $this->createMock(MassDelete::class),
            fn () => $this->mark->isActive() ? 'marked' : 'unmarked'
        );

        self::assertSame('marked', $result);
        self::assertFalse($this->mark->isActive());
    }

    /**
     * Run the observer as the grid's Delete action does: inside the plugin.
     */
    private function deleteInTheGrid(SubscriberDeleteAfter $observer, Observer $event): void
    {
        (new MarkSubscriberMassDelete($this->mark))->aroundExecute(
            $this->createMock(MassDelete::class),
            static fn () => $observer->execute($event)
        );
    }

    private function observer(bool $active = true, ?CustomerRepositoryInterface $repository = null): SubscriberDeleteAfter
    {
        $config = $this->createMock(Config::class);
        $config->method('isContactSyncActive')->with(1)->willReturn($active);
        $config->method('isConnected')->with(3)->willReturn(true);

        return new SubscriberDeleteAfter(
            $config,
            $this->mark,
            $this->dispatcher,
            $repository ?? $this->createMock(CustomerRepositoryInterface::class)
        );
    }

    private function eventFor(int $status, int $customerId = 0): Observer
    {
        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStatus', 'getEmail'])
            ->getMock();
        $subscriber->method('getStatus')->willReturn($status);
        $subscriber->method('getEmail')->willReturn('person@example.com');
        $subscriber->setData(['store_id' => 3, 'customer_id' => $customerId]);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('subscriber')->willReturn($subscriber);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
