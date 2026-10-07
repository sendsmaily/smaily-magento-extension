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
use Smaily\Connect\Observer\Engine\SubscriberUnsubscribed;

/**
 * PRO-3578: an unsubscribe from marketing also stops profiling — whether the
 * shopper unsubscribed in the store or Smaily's consent mirror wrote it.
 */
class SubscriberUnsubscribedTest extends TestCase
{
    private ProfilingConsent&MockObject $profilingConsent;
    private bool $engineConnected = true;

    protected function setUp(): void
    {
        $this->profilingConsent = $this->createMock(ProfilingConsent::class);
    }

    public function testAnUnsubscribeStopsProfiling(): void
    {
        $this->profilingConsent->expects(self::once())->method('optOutOnUnsubscribe')->with('person@example.com');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, true));
    }

    public function testASubscribeLeavesProfilingAlone(): void
    {
        $this->profilingConsent->expects(self::never())->method('optOutOnUnsubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, true));
    }

    public function testASaveThatDidNotChangeTheStatusIsNotAnUnsubscribe(): void
    {
        $this->profilingConsent->expects(self::never())->method('optOutOnUnsubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, false));
    }

    public function testNothingHappensWithoutCampaignIntelligence(): void
    {
        $this->engineConnected = false;
        $this->profilingConsent->expects(self::never())->method('optOutOnUnsubscribe');

        $this->observer()->execute($this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, true));
    }

    private function observer(): SubscriberUnsubscribed
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($this->engineConnected);

        return new SubscriberUnsubscribed($settings, $this->profilingConsent);
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
