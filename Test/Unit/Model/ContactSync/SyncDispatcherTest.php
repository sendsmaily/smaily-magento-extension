<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\ContactSync;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

class SyncDispatcherTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $enqueued = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $cancelled = [];

    /** Whether the contact's abandoned-cart reminder went out to Smaily. */
    private bool $reminderDelivered = true;

    private SubscriberPayloadBuilder&MockObject $payloadBuilder;
    private SyncDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->enqueued = [];
        $this->cancelled = [];

        $this->payloadBuilder = $this->createMock(SubscriberPayloadBuilder::class);
        $languageResolver = $this->createMock(LanguageResolver::class);
        $languageResolver->method('forStore')->willReturn('en');

        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $eventQueue = $this->createMock(EventQueue::class);
        $eventQueue->method('cancelPendingAutomation')->willReturnCallback(
            function (string $trigger, string $entityId): int {
                $this->cancelled[] = [$trigger, $entityId];

                return 0;
            }
        );
        $eventQueue->method('hasDeliveredAutomation')->willReturnCallback(
            fn (string $trigger, string $entityId): bool => $this->reminderDelivered
                && [$trigger, $entityId] === [Trigger::ABANDONED_CART, 'shopper@example.com']
        );
        $eventQueue->method('enqueue')->willReturnCallback(
            function (string $eventType, array $payload): bool {
                $this->enqueued[] = ['event_type' => $eventType, 'payload' => $payload];

                return true;
            }
        );

        $this->dispatcher = new SyncDispatcher(
            $this->payloadBuilder,
            $languageResolver,
            $storeManager,
            $eventQueue
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function markerProvider(): array
    {
        return [
            'welcome' => [Trigger::WELCOME, 'welcome_automation_at'],
            'first order' => [Trigger::FIRST_ORDER, 'first_order_automation_at'],
            'abandoned cart' => [Trigger::ABANDONED_CART, 'abandoned_cart_automation_at'],
        ];
    }

    /**
     * @dataProvider markerProvider
     */
    public function testEachTriggerStampsItsOwnMarkerWithTheRunTimestamp(string $trigger, string $field): void
    {
        $before = gmdate('Y-m-d H:i:s');
        $this->dispatcher->dispatchAutomation($trigger, 1, ['email' => 'shopper@example.com']);
        $after = gmdate('Y-m-d H:i:s');

        $address = $this->enqueued[0]['payload']['address'];
        self::assertArrayHasKey($field, $address);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $address[$field]);
        self::assertGreaterThanOrEqual($before, $address[$field]);
        self::assertLessThanOrEqual($after, $address[$field]);

        // Only its own — a trigger never speaks for one that did not fire.
        foreach (Trigger::MARKER_FIELDS as $other) {
            if ($other !== $field) {
                self::assertArrayNotHasKey($other, $address);
            }
        }
    }

    public function testExistingPayloadFieldsAreLeftUntouched(): void
    {
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, [
            'email' => 'shopper@example.com',
            'is_abandoned_cart' => 'true',
        ]);

        $address = $this->enqueued[0]['payload']['address'];
        self::assertSame('true', $address['is_abandoned_cart']);
        self::assertSame('shopper@example.com', $address['email']);
    }

    // PRO-2453: the wire name is pinned, and the stamp sorts against a run
    // marker of the same shape (rationale: Trigger::MARKER_STAMP_FORMAT).
    public function testTheCartPurchaseMarkerIsSentAloneAndSortsAgainstTheRunMarker(): void
    {
        $before = gmdate('Y-m-d H:i:s');
        $this->dispatcher->dispatchCartPurchase('shopper@example.com', 1);
        $after = gmdate('Y-m-d H:i:s');

        self::assertSame(
            [[Trigger::ABANDONED_CART, 'shopper@example.com']],
            $this->cancelled,
            'A reminder still queued for this contact is withdrawn first'
        );
        self::assertSame(EventType::CONTACT_SYNC, $this->enqueued[0]['event_type'], 'No automation is triggered');
        $contact = $this->enqueued[0]['payload']['contact'];
        self::assertSame(
            ['email', Trigger::ABANDONED_CART_PURCHASED_FIELD],
            array_keys($contact),
            'The address and the one field, nothing that could rewrite the reminder'
        );
        self::assertSame('abandoned_cart_purchased_at', Trigger::ABANDONED_CART_PURCHASED_FIELD);
        $stamp = $contact[Trigger::ABANDONED_CART_PURCHASED_FIELD];
        self::assertGreaterThanOrEqual($before, $stamp);
        self::assertLessThanOrEqual($after, $stamp);
    }

    /**
     * PRO-3619: a reminder that never went out reached no contact, and
     * Smaily creates a contact sent without a status as subscribed — so
     * there is no marker to send, only the reminder to withdraw.
     */
    public function testNoCartPurchaseMarkerWhenTheReminderNeverWentOut(): void
    {
        $this->reminderDelivered = false;

        $this->dispatcher->dispatchCartPurchase('shopper@example.com', 1);

        self::assertSame([[Trigger::ABANDONED_CART, 'shopper@example.com']], $this->cancelled);
        self::assertSame([], $this->enqueued);
    }

    /**
     * PRO-3616: Smaily creates a contact sent without a status as subscribed,
     * and a reminder sent to an address Smaily does not have creates nothing.
     * A store unsubscribe therefore travels with the marker.
     */
    public function testTheCartPurchaseMarkerOfAStoreUnsubscribeCarriesTheUnsubscribe(): void
    {
        $this->dispatcher->dispatchCartPurchase('shopper@example.com', 1, true);

        $contact = $this->enqueued[0]['payload']['contact'];
        self::assertSame(
            ['email', Trigger::ABANDONED_CART_PURCHASED_FIELD, 'is_unsubscribed'],
            array_keys($contact)
        );
        self::assertSame(1, $contact['is_unsubscribed']);
    }

    /**
     * PRO-3628: a contact sync that adds nothing to the one this request
     * already queued for the address — the same fields with the same
     * values, or fewer of them — is not queued again.
     *
     * @return array<string, array{0: list<array<string, string|int>>, 1: int}>
     */
    public static function repeatProvider(): array
    {
        $subscribed = ['email' => 'shopper@example.com', 'is_unsubscribed' => 0, 'first_name' => 'Test'];
        $noStatus = ['email' => 'shopper@example.com', 'first_name' => 'Test'];
        $unsubscribed = ['is_unsubscribed' => 1] + $subscribed;

        return [
            'the same contact twice' => [[$subscribed, $subscribed], 1],
            'no status after the status' => [[$subscribed, $noStatus], 1],
            'the status after no status' => [[$noStatus, $subscribed], 2],
            'a changed field' => [[$subscribed, ['first_name' => 'Changed'] + $subscribed], 2],
            'a field added' => [[$subscribed, $subscribed + ['last_name' => 'Person']], 2],
            'subscribed, unsubscribed, subscribed' => [[$subscribed, $unsubscribed, $subscribed], 3],
        ];
    }

    /**
     * @dataProvider repeatProvider
     * @param list<array<string, string|int>> $contacts
     */
    public function testAContactSyncThatAddsNothingIsNotQueuedAgain(array $contacts, int $queued): void
    {
        $this->payloadBuilder->method('build')->willReturnOnConsecutiveCalls(...$contacts);

        foreach ($contacts as $ignored) {
            $this->dispatcher->dispatchContactSync('shopper@example.com', 1, null);
        }

        self::assertCount($queued, $this->enqueued);
    }

    public function testTheSameContactForAnotherStoreIsQueued(): void
    {
        $this->payloadBuilder->method('build')->willReturn(['email' => 'shopper@example.com']);

        $this->dispatcher->dispatchContactSync('shopper@example.com', 1, null);
        $this->dispatcher->dispatchContactSync('shopper@example.com', 2, null);

        self::assertCount(2, $this->enqueued);
    }

    /**
     * The purchase marker row can be closed unsent (PRO-3619), so a contact
     * sync is never held back because of it, and it is never held back
     * either.
     */
    public function testTheCartPurchaseMarkerNeitherHoldsBackNorIsHeldBack(): void
    {
        $this->payloadBuilder->method('build')->willReturn(['email' => 'shopper@example.com']);

        $this->dispatcher->dispatchCartPurchase('shopper@example.com', 1);
        $this->dispatcher->dispatchContactSync('shopper@example.com', 1, null);
        $this->dispatcher->dispatchCartPurchase('shopper@example.com', 1);

        self::assertCount(3, $this->enqueued);
    }

    public function testContactSyncCarriesNoMarker(): void
    {
        $this->payloadBuilder->method('build')->willReturn(['email' => 'shopper@example.com']);

        $this->dispatcher->dispatchContactSync('shopper@example.com', 1, false);

        $contact = $this->enqueued[0]['payload']['contact'];
        foreach (Trigger::MARKER_FIELDS as $field) {
            self::assertArrayNotHasKey($field, $contact);
        }
    }
}
