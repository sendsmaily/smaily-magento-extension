<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use Magento\Newsletter\Model\Subscriber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Observer\SubscriberSaveAfter;

/**
 * PRO-3580: only a subscription the shopper makes on the storefront fires
 * the welcome automation. A subscription made in the admin, through the API
 * or by an import still syncs the contact, but fires no welcome.
 */
class SubscriberSaveAfterTest extends TestCase
{
    private SyncDispatcher&MockObject $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(SyncDispatcher::class);
        $this->dispatcher->method('websiteId')->willReturn(1);
    }

    /**
     * PRO-3580: a storefront subscription fires the welcome automation.
     */
    public function testAStorefrontSubscriptionFiresTheWelcome(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync');
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, self::anything());

        $this->observer('frontend')->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * PRO-3580: a storefront resubscription (unsubscribed -> subscribed)
     * fires the welcome automation too.
     */
    public function testAStorefrontResubscriptionFiresTheWelcome(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, self::anything());

        $this->observer('frontend')->execute(
            $this->eventFor(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED)
        );
    }

    /**
     * PRO-3580: the checkout opt-in is a storefront subscription too, also
     * when the checkout places the order through the REST API (Luma).
     */
    public function testACheckoutOptInDuringARestOrderPlacementFiresTheWelcome(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, self::anything());

        $storefrontSubscription = new StorefrontSubscription();
        $storefrontSubscription->run(function () use ($storefrontSubscription): void {
            $this->observer('webapi_rest', $storefrontSubscription)
                ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        });
    }

    /**
     * PRO-3580: a subscription made in the admin, through the REST, SOAP or
     * GraphQL API, or by a cron or command-line import fires no welcome. The
     * contact still syncs.
     *
     * @dataProvider nonStorefrontAreaProvider
     */
    public function testANonStorefrontSubscriptionSyncsTheContactWithoutAWelcome(?string $area): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, false, null);
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');

        $this->observer($area)->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function nonStorefrontAreaProvider(): array
    {
        return [
            'admin' => ['adminhtml'],
            'REST API' => ['webapi_rest'],
            'SOAP API' => ['webapi_soap'],
            'GraphQL API' => ['graphql'],
            'cron import' => ['crontab'],
            'command-line import, no area' => [null],
        ];
    }

    private function observer(
        ?string $area,
        ?StorefrontSubscription $storefrontSubscription = null
    ): SubscriberSaveAfter {
        $config = $this->createMock(Config::class);
        $config->method('isSyncEnabled')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
        $config->method('isWelcomeEnabled')->willReturn(true);

        $state = $this->createMock(State::class);
        if ($area === null) {
            $state->method('getAreaCode')->willThrowException(
                new LocalizedException(new Phrase('Area code is not set'))
            );
        } else {
            $state->method('getAreaCode')->willReturn($area);
        }

        $payloadBuilder = $this->createMock(SubscriberPayloadBuilder::class);
        $payloadBuilder->method('build')->willReturn(['email' => 'person@example.com']);

        return new SubscriberSaveAfter(
            $config,
            $this->createMock(ReconcileGuard::class),
            $this->dispatcher,
            $payloadBuilder,
            $this->createMock(CustomerRepositoryInterface::class),
            $state,
            $storefrontSubscription ?? new StorefrontSubscription()
        );
    }

    private function eventFor(int $status, ?int $previousStatus = null): Observer
    {
        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isStatusChanged', 'getStatus', 'getEmail'])
            ->getMock();
        $subscriber->method('isStatusChanged')->willReturn(true);
        $subscriber->method('getStatus')->willReturn($status);
        $subscriber->method('getEmail')->willReturn('person@example.com');
        $subscriber->setData('store_id', 1);
        $subscriber->setOrigData('subscriber_status', $previousStatus);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('subscriber')->willReturn($subscriber);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
