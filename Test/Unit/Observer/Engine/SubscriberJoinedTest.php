<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\CustomerIngest;
use Smaily\Connect\Observer\Engine\SubscriberJoined;

/**
 * CON-266: a customer's subscription that enters a status the contact sync
 * sends (subscribed or unsubscribed) may put the customer into the Campaign
 * Intelligence customer scope's group (a); the account is offered to the
 * live customer sync, which checks the scope. A move between the two sent
 * statuses changes no group.
 */
class SubscriberJoinedTest extends TestCase
{
    /**
     * @dataProvider joins
     */
    public function testEnteringASentStatusOffersTheCustomer(?int $from, int $to): void
    {
        $ingest = $this->createMock(CustomerIngest::class);
        $ingest->expects(self::once())->method('joined')->with(42);

        (new SubscriberJoined($ingest))->execute($this->observer($from, $to, true, 42));
    }

    /**
     * @return array<string, array{0: int|null, 1: int}>
     */
    public static function joins(): array
    {
        return [
            'a new subscription' => [null, Subscriber::STATUS_SUBSCRIBED],
            'a confirmed pending subscription' => [Subscriber::STATUS_NOT_ACTIVE, Subscriber::STATUS_SUBSCRIBED],
            'an unconfirmed one' => [Subscriber::STATUS_UNCONFIRMED, Subscriber::STATUS_SUBSCRIBED],
            'a pending one turned unsubscribed' => [Subscriber::STATUS_NOT_ACTIVE, Subscriber::STATUS_UNSUBSCRIBED],
        ];
    }

    /**
     * @dataProvider noJoins
     */
    public function testAnythingElseOffersNothing(?int $from, int $to, bool $changed): void
    {
        $ingest = $this->createMock(CustomerIngest::class);
        $ingest->expects(self::never())->method('joined');

        (new SubscriberJoined($ingest))->execute($this->observer($from, $to, $changed, 42));
    }

    /**
     * @return array<string, array{0: int|null, 1: int, 2: bool}>
     */
    public static function noJoins(): array
    {
        return [
            'an unsubscribe of a subscriber' => [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED, true],
            'a resubscription' => [Subscriber::STATUS_UNSUBSCRIBED, Subscriber::STATUS_SUBSCRIBED, true],
            'a pending confirmation' => [null, Subscriber::STATUS_NOT_ACTIVE, true],
            'a save that keeps the status' => [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_SUBSCRIBED, false],
        ];
    }

    private function observer(?int $from, int $to, bool $changed, int $customerId): Observer
    {
        // getCustomerId is a magic getter on the model (addMethods).
        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isStatusChanged', 'getStatus', 'getOrigData'])
            ->addMethods(['getCustomerId'])
            ->getMock();
        $subscriber->method('isStatusChanged')->willReturn($changed);
        $subscriber->method('getStatus')->willReturn($to);
        $subscriber->method('getCustomerId')->willReturn($customerId);
        $subscriber->method('getOrigData')->willReturnCallback(
            static fn (?string $key = null) => $key === 'subscriber_status' ? $from : null
        );
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('subscriber')->willReturn($subscriber);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
