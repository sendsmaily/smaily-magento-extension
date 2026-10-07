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
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Observer\Engine\SubscriberResubscribed;

/**
 * PRO-3594: subscribing to marketing again switches profiling back on when
 * the opt-out came only from unsubscribing — whether the shopper subscribed
 * in the store or Smaily's consent mirror wrote it.
 */
class SubscriberResubscribedTest extends TestCase
{
    private ProfilingConsent&MockObject $profilingConsent;
    private bool $engineConnected = true;

    protected function setUp(): void
    {
        $this->profilingConsent = $this->createMock(ProfilingConsent::class);
    }

    public function testASubscribeIsPassedOn(): void
    {
        $this->profilingConsent->expects(self::once())->method('optInOnResubscribe')->with('person@example.com');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, true));
    }

    public function testAnUnsubscribeIsNotASubscribe(): void
    {
        $this->profilingConsent->expects(self::never())->method('optInOnResubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, true));
    }

    public function testASaveThatDidNotChangeTheStatusIsNotASubscribe(): void
    {
        $this->profilingConsent->expects(self::never())->method('optInOnResubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, false));
    }

    public function testNothingHappensWithoutCampaignIntelligence(): void
    {
        $this->engineConnected = false;
        $this->profilingConsent->expects(self::never())->method('optInOnResubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, true));
    }

    private function observer(): SubscriberResubscribed
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($this->engineConnected);

        return new SubscriberResubscribed($settings, $this->profilingConsent);
    }

    private function eventFor(int $status, bool $changed): Observer
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('getStatus')->willReturn($status);
        $subscriber->method('isStatusChanged')->willReturn($changed);
        $subscriber->method('getEmail')->willReturn('person@example.com');

        return new Observer(['event' => new Event(['subscriber' => $subscriber])]);
    }
}
