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
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Observer\SubscriberSaveAfter;

/**
 * PRO-3580: only a subscription the shopper makes on the storefront fires
 * the welcome automation. A subscription made in the admin, through the API
 * or by an import still syncs the contact, but fires no welcome.
 *
 * PRO-3606: in the checkout-opt-in-only mode only the checkout opt-in
 * creates or updates a Smaily contact; a newsletter-form signup alone does
 * not.
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

    /**
     * PRO-3606: in the checkout-opt-in-only mode a newsletter-form signup
     * alone sends nothing to Smaily: no contact and no welcome.
     */
    public function testInCheckoutOptInOnlyModeANewsletterFormSignupSendsNothing(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');

        $this->observer('frontend', null, SyncMode::MODE_CHECKOUT_OPTIN)
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * PRO-3606: in the checkout-opt-in-only mode the checkout opt-in syncs
     * the contact as subscribed.
     */
    public function testInCheckoutOptInOnlyModeTheCheckoutOptInSyncsTheContact(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, false, null);

        $storefrontSubscription = new StorefrontSubscription();
        $storefrontSubscription->run(function () use ($storefrontSubscription): void {
            $this->observer('webapi_rest', $storefrontSubscription, SyncMode::MODE_CHECKOUT_OPTIN)
                ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        });
    }

    /**
     * PRO-3606: with Magento's "Need to Confirm" on, the checkout opt-in is
     * saved as pending and confirmed later from the confirmation email. The
     * store cannot tell that confirmation from a confirmed newsletter-form
     * signup, so a confirmed subscription syncs in the
     * checkout-opt-in-only mode.
     */
    public function testInCheckoutOptInOnlyModeAConfirmedPendingSubscriptionSyncs(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, false, null);

        $this->observer('frontend', null, SyncMode::MODE_CHECKOUT_OPTIN)
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_NOT_ACTIVE));
    }

    /**
     * PRO-3606: an unsubscribe in the store still reaches Smaily in the
     * checkout-opt-in-only mode, so a contact who opted in at checkout does
     * not stay subscribed there.
     */
    public function testInCheckoutOptInOnlyModeAnUnsubscribeSyncs(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, true, null);

        $this->observer('frontend', null, SyncMode::MODE_CHECKOUT_OPTIN)->execute(
            $this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, Subscriber::STATUS_SUBSCRIBED)
        );
    }

    /**
     * PRO-3606: in the other two modes a newsletter-form signup syncs the
     * contact as before.
     *
     * @dataProvider signupSyncingModeProvider
     */
    public function testInTheOtherModesANewsletterFormSignupSyncsTheContact(string $mode): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, false, null);

        $this->observer('frontend', null, $mode)->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function signupSyncingModeProvider(): array
    {
        return [
            'subscribers only (consent)' => [SyncMode::MODE_CONSENT],
            'all customers (legitimate interest)' => [SyncMode::MODE_LEGITIMATE_INTEREST],
        ];
    }

    private function observer(
        ?string $area,
        ?StorefrontSubscription $storefrontSubscription = null,
        string $mode = SyncMode::MODE_CONSENT
    ): SubscriberSaveAfter {
        $config = $this->createMock(Config::class);
        $config->method('isSyncEnabled')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
        $config->method('isWelcomeEnabled')->willReturn(true);
        $config->method('getSyncMode')->willReturn($mode);

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
            new Mode($config),
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
