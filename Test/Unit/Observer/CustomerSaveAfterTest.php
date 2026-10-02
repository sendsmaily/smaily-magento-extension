<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Observer\CustomerSaveAfter;

/**
 * PRO-3616: in the all-customers mode (soft opt-in) a profile save sends a
 * customer who unsubscribed in the store as unsubscribed. Smaily creates a
 * new contact sent without a status as subscribed, so the status is omitted
 * only where the store knows of no unsubscribe.
 */
class CustomerSaveAfterTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Support/Stub/SubscriberFactory.php';
    }

    /**
     * @dataProvider statusProvider
     */
    public function testAProfileSaveSendsTheStatusTheStoreKnows(
        string $mode,
        int $status,
        bool $sends,
        ?bool $expected
    ): void {
        $dispatcher = $this->createMock(SyncDispatcher::class);
        $dispatcher->expects($sends ? self::once() : self::never())
            ->method('dispatchContactSync')
            ->with('person@example.com', 1, $expected, self::isInstanceOf(CustomerInterface::class));

        $this->observer($mode, $status, $dispatcher)->execute($this->event());
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: bool, 3: ?bool}>
     */
    public static function statusProvider(): array
    {
        $all = SyncMode::MODE_LEGITIMATE_INTEREST;

        return [
            'all customers, unsubscribed in the store' => [$all, Subscriber::STATUS_UNSUBSCRIBED, true, true],
            'all customers, no newsletter record' => [$all, 0, true, null],
            'all customers, waiting for confirmation' => [$all, Subscriber::STATUS_NOT_ACTIVE, true, null],
            'all customers, subscribed' => [$all, Subscriber::STATUS_SUBSCRIBED, true, null],
            'subscribers only, subscribed' => [SyncMode::MODE_CONSENT, Subscriber::STATUS_SUBSCRIBED, true, false],
            'subscribers only, unsubscribed' => [SyncMode::MODE_CONSENT, Subscriber::STATUS_UNSUBSCRIBED, false, null],
        ];
    }

    private function observer(string $mode, int $status, SyncDispatcher $dispatcher): CustomerSaveAfter
    {
        $config = $this->createMock(Config::class);
        $config->method('isSyncEnabled')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
        $config->method('getSyncMode')->willReturn($mode);

        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('loadByCustomer')->with(42, 1)->willReturnSelf();
        $subscriber->method('getStatus')->willReturn($status);
        $subscriberFactory = $this->createMock(SubscriberFactory::class);
        $subscriberFactory->method('create')->willReturn($subscriber);

        return new CustomerSaveAfter($config, new Mode($config), new ReconcileGuard(), $dispatcher, $subscriberFactory);
    }

    private function event(): Observer
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getStoreId')->willReturn(1);
        $customer->method('getWebsiteId')->willReturn(1);
        $customer->method('getEmail')->willReturn('person@example.com');

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('customer_data_object')->willReturn($customer);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
