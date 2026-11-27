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
use Smaily\Connect\Model\ContactSync\UnconnectedSignup;
use Smaily\Connect\Model\WebsiteConnections;
use Smaily\Connect\Observer\SubscriberSaveAfter;

/**
 * PRO-3580: only a subscription the shopper makes on the storefront fires
 * the welcome automation. A subscription made in the admin, through the API
 * or by an import still syncs the contact, but fires no welcome.
 *
 * PRO-3606: in the checkout-opt-in-only mode only the checkout opt-in
 * creates or updates a Smaily contact; a newsletter-form signup alone does
 * not.
 *
 * PRO-4036: before contact sync is active for the website (its Contacts
 * settings not saved yet), nothing goes to Smaily.
 *
 * PRO-4322: a status change is the saved status differing from the loaded
 * one, not the subscriber's status-changed flag.
 *
 * CON-470: a signup on a website without a Smaily connection, while another
 * website has one, is recorded in the Log as skipped; nothing is sent.
 *
 * CON-477: the welcome follows the shopper's own storefront subscription,
 * not contact sync: it fires while contact sync is off and for a signup the
 * contact sync mode leaves out, and then carries the contact's language.
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
     * contact still syncs as subscribed (CON-196): a status change is a
     * consent act whoever makes it, and may re-subscribe a contact who
     * unsubscribed in Smaily.
     *
     * @dataProvider nonStorefrontAreaProvider
     */
    public function testANonStorefrontSubscriptionSyncsTheContactWithoutAWelcome(?string $area): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, self::identicalTo(false), null);
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');

        $this->observer($area)->execute(
            $this->eventFor(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED)
        );
    }

    /**
     * CON-196: an unsubscribe goes as 1 in every context too.
     *
     * @dataProvider nonStorefrontAreaProvider
     */
    public function testAnUnsubscribeSyncsInEveryContext(?string $area): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, self::identicalTo(true), null);

        $this->observer($area)->execute(
            $this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, Subscriber::STATUS_SUBSCRIBED)
        );
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
     * alone syncs no contact. CON-477: it still gets the welcome, which
     * carries the contact's language, as no contact sync stores it.
     */
    public function testInCheckoutOptInOnlyModeANewsletterFormSignupGetsOnlyTheWelcome(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, ['email' => 'person@example.com', 'language' => 'et']);

        $this->observer('frontend', null, SyncMode::MODE_CHECKOUT_OPTIN)
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * CON-477: with contact sync active, the contact sync queued first
     * stores the language, so the welcome after it leaves it out, as before.
     */
    public function testWithContactSyncTheWelcomeFollowsTheContactSyncWithoutTheLanguage(): void
    {
        $calls = [];
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'contact';
            });
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, ['email' => 'person@example.com'])
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'welcome';
            });

        $this->observer('frontend')->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));

        self::assertSame(['contact', 'welcome'], $calls);
    }

    /**
     * CON-477: with contact sync off (or before its Contacts settings are
     * saved), a storefront subscription — the newsletter form, the account's
     * newsletter box, the checkout opt-in — gets the welcome, with the
     * contact's language, and syncs no contact.
     *
     * @dataProvider storefrontSubscriptionProvider
     */
    public function testWithContactSyncOffAStorefrontSubscriptionGetsTheWelcome(string $area, bool $checkout): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, ['email' => 'person@example.com', 'language' => 'et']);

        $storefrontSubscription = new StorefrontSubscription();
        $subscribe = function () use ($area, $storefrontSubscription): void {
            $this->observer($area, $storefrontSubscription, SyncMode::MODE_CONSENT, false)
                ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED));
        };
        $checkout ? $storefrontSubscription->run($subscribe) : $subscribe();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function storefrontSubscriptionProvider(): array
    {
        return [
            'the newsletter form or the account\'s newsletter box' => ['frontend', false],
            'the checkout opt-in, placed through the REST API' => ['webapi_rest', true],
        ];
    }

    /**
     * CON-477: with contact sync off and "Need to Confirm" on, the pending
     * signup sends nothing; its confirmation sends the welcome.
     */
    public function testWithContactSyncOffAConfirmedSignupGetsTheWelcomeOnlyOnConfirmation(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::once())->method('dispatchAutomation')
            ->with(Trigger::WELCOME, 1, ['email' => 'person@example.com', 'language' => 'et']);

        $observer = $this->observer('frontend', null, SyncMode::MODE_CONSENT, false);
        $observer->execute($this->eventFor(Subscriber::STATUS_NOT_ACTIVE));
        $observer->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_NOT_ACTIVE));
    }

    /**
     * CON-477: with contact sync off, a subscription made in the admin,
     * through the API or by an import still sends no welcome, and an
     * unsubscribe sends nothing.
     *
     * @dataProvider nonStorefrontAreaProvider
     */
    public function testWithContactSyncOffANonStorefrontSubscriptionSendsNothing(?string $area): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');

        $this->observer($area, null, SyncMode::MODE_CONSENT, false)
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        $this->observer('frontend', null, SyncMode::MODE_CONSENT, false)
            ->execute($this->eventFor(Subscriber::STATUS_UNSUBSCRIBED, Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * CON-477: with contact sync off and the welcome off, a storefront
     * subscription sends nothing; on a website without a Smaily connection
     * it sends nothing and records nothing, as contact sync would not run.
     */
    public function testWithContactSyncOffNoWelcomeOrNoConnectionSendsNothing(): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');
        $this->dispatcher->expects(self::never())->method('recordSkippedContactSync');

        $this->observer('frontend', null, SyncMode::MODE_CONSENT, false, true, [], false)
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        $this->observer('frontend', null, SyncMode::MODE_CONSENT, false, false, [1 => 'Second'])
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * PRO-3606: in the checkout-opt-in-only mode the checkout opt-in syncs
     * the contact as subscribed.
     */
    public function testInCheckoutOptInOnlyModeTheCheckoutOptInSyncsTheContact(): void
    {
        $this->dispatcher->expects(self::once())->method('dispatchContactSync')
            ->with('person@example.com', 1, self::identicalTo(false), null);

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
            ->with('person@example.com', 1, self::identicalTo(false), null);

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
            ->with('person@example.com', 1, self::identicalTo(true), null);

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
            ->with('person@example.com', 1, self::identicalTo(false), null);

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

    /**
     * PRO-4322: a save that keeps the loaded status sends nothing, whatever
     * the subscriber's status-changed flag says.
     *
     * @dataProvider statusChangeProvider
     */
    public function testASaveThatKeepsTheStatusSendsNothing(int $status): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');

        $event = $this->eventFor($status, $status);
        $event->getEvent()->getData('subscriber')->setStatusChanged(true);
        $this->observer('frontend')->execute($event);
    }

    /**
     * PRO-4036: a subscription or an unsubscribe on a website whose
     * Contacts settings were never saved queues no contact (the welcome
     * follows the subscription, CON-477).
     *
     * @dataProvider statusChangeProvider
     */
    public function testNoContactIsQueuedBeforeContactSyncIsActive(int $status): void
    {
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');

        $this->observer('frontend', null, SyncMode::MODE_CONSENT, false)->execute($this->eventFor($status));
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function statusChangeProvider(): array
    {
        return [
            'subscribe' => [Subscriber::STATUS_SUBSCRIBED],
            'unsubscribe' => [Subscriber::STATUS_UNSUBSCRIBED],
        ];
    }

    /**
     * CON-470: the website has no Smaily connection while another website
     * has one — the subscription or unsubscribe is recorded as skipped, with
     * what would have been sent, and nothing is queued to be sent.
     *
     * @dataProvider statusChangeProvider
     */
    public function testAStatusChangeOnAWebsiteWithoutAConnectionIsRecordedAsSkipped(int $status): void
    {
        $this->dispatcher->expects(self::once())->method('recordSkippedContactSync')
            ->with(
                'person@example.com',
                1,
                $status !== Subscriber::STATUS_SUBSCRIBED,
                null,
                WebsiteConnections::SKIPPED_NOT_CONNECTED
            );
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');
        $this->dispatcher->expects(self::never())->method('dispatchAutomation');

        $this->observer('frontend', null, SyncMode::MODE_CONSENT, true, false, [1 => 'Second'])
            ->execute($this->eventFor($status));
    }

    /**
     * CON-470: a store with no website connected (not set up yet) records
     * nothing, nor does a website whose contact sync would not be active once
     * connected, a save that does not change the status, or a newsletter-form
     * signup in the checkout-opt-in-only mode, which would not sync.
     */
    public function testAWebsiteWithoutAConnectionRecordsNothingElse(): void
    {
        $this->dispatcher->expects(self::never())->method('recordSkippedContactSync');
        $this->dispatcher->expects(self::never())->method('dispatchContactSync');

        $this->observer('frontend', null, SyncMode::MODE_CONSENT, true, false, [])
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        $this->observer('frontend', null, SyncMode::MODE_CONSENT, false, false, [1 => 'Second'])
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        $this->observer('frontend', null, SyncMode::MODE_CHECKOUT_OPTIN, true, false, [1 => 'Second'])
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED));
        $this->observer('frontend', null, SyncMode::MODE_CONSENT, true, false, [1 => 'Second'])
            ->execute($this->eventFor(Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_SUBSCRIBED));
    }

    /**
     * @param array<int, string> $unconnected the websites without a Smaily connection
     */
    private function observer(
        ?string $area,
        ?StorefrontSubscription $storefrontSubscription = null,
        string $mode = SyncMode::MODE_CONSENT,
        bool $active = true,
        bool $connected = true,
        array $unconnected = [],
        bool $welcome = true
    ): SubscriberSaveAfter {
        $config = $this->createMock(Config::class);
        $config->method('isContactSyncActive')->with(1)->willReturn($active);
        $config->method('isConnected')->willReturn($connected);
        $config->method('isWelcomeEnabled')->willReturn($welcome);
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
        $payloadBuilder->method('build')->willReturn(['email' => 'person@example.com', 'language' => 'et']);

        $websiteConnections = $this->createMock(WebsiteConnections::class);
        $websiteConnections->method('isUnconnectedWhileAnotherIs')
            ->willReturnCallback(static fn (int $websiteId): bool => isset($unconnected[$websiteId]));

        return new SubscriberSaveAfter(
            $config,
            new Mode($config),
            $this->createMock(ReconcileGuard::class),
            $this->dispatcher,
            $payloadBuilder,
            $this->createMock(CustomerRepositoryInterface::class),
            $state,
            $storefrontSubscription ?? new StorefrontSubscription(),
            new UnconnectedSignup(
                $config,
                $websiteConnections,
                $this->dispatcher
            )
        );
    }

    private function eventFor(int $status, ?int $previousStatus = null): Observer
    {
        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEmail'])
            ->getMock();
        $subscriber->method('getEmail')->willReturn('person@example.com');
        $subscriber->setData('store_id', 1);
        $subscriber->setData('subscriber_status', $status);
        $subscriber->setOrigData('subscriber_status', $previousStatus);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('subscriber')->willReturn($subscriber);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }
}
