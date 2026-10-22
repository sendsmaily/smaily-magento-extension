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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\ContactSync\AdminSubscriberDelete;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Observer\Engine\SubscriberDeleted;

/**
 * CON-187: a subscriber deleted in the admin's subscriber grid stops
 * profiling, as an unsubscribe in the store does; any other delete of a
 * subscriber row (a customer account's deletion) does not.
 */
class SubscriberDeletedTest extends TestCase
{
    private ProfilingConsent&MockObject $profilingConsent;
    private AdminSubscriberDelete $mark;
    private bool $engineConnected = true;

    protected function setUp(): void
    {
        $this->profilingConsent = $this->createMock(ProfilingConsent::class);
        $this->mark = new AdminSubscriberDelete();
    }

    public function testADeleteInTheGridStopsProfiling(): void
    {
        $this->profilingConsent->expects(self::once())->method('optOutOnUnsubscribe')->with('person@example.com');

        $this->deleteInTheGrid(Subscriber::STATUS_SUBSCRIBED);
    }

    public function testADeleteOfAPendingRowInTheGridStopsProfiling(): void
    {
        $this->profilingConsent->expects(self::once())->method('optOutOnUnsubscribe')->with('person@example.com');

        $this->deleteInTheGrid(Subscriber::STATUS_NOT_ACTIVE);
    }

    public function testADeleteOutsideTheGridLeavesProfilingAlone(): void
    {
        $this->profilingConsent->expects(self::never())->method('optOutOnUnsubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    public function testAnAlreadyUnsubscribedRowIsNotOptedOutAgain(): void
    {
        $this->profilingConsent->expects(self::never())->method('optOutOnUnsubscribe');

        $this->deleteInTheGrid(Subscriber::STATUS_UNSUBSCRIBED);
    }

    public function testNothingHappensWithoutCampaignIntelligence(): void
    {
        $this->engineConnected = false;
        $this->profilingConsent->expects(self::never())->method('optOutOnUnsubscribe');

        $this->deleteInTheGrid(Subscriber::STATUS_SUBSCRIBED);
    }

    private function deleteInTheGrid(int $status): void
    {
        $observer = $this->observer();
        $event = $this->eventFor($status);
        $this->mark->run(static fn () => $observer->execute($event));
    }

    private function observer(): SubscriberDeleted
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($this->engineConnected);

        return new SubscriberDeleted($settings, $this->mark, $this->profilingConsent);
    }

    private function eventFor(int $status): Observer
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStatus')->willReturn($status);
        $subscriber->method('getEmail')->willReturn('person@example.com');

        return new Observer(['event' => new Event(['subscriber' => $subscriber])]);
    }
}
