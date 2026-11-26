<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\AbandonedCart\ReminderConsent;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\ContactSync\Mode;

/**
 * CON-195: who gets an abandoned-cart reminder in each contact-sync mode.
 */
class ReminderConsentTest extends TestCase
{
    private const CARTS = [
        1 => ['email' => 'customer@example.com', 'customer_id' => 7],
        2 => ['email' => 'ticked@example.com', 'customer_id' => 0],
        3 => ['email' => 'Signed-Up@example.com', 'customer_id' => 0],
        4 => ['email' => 'anyone@example.com', 'customer_id' => 0],
        5 => ['email' => 'renamed@example.com', 'customer_id' => 9],
        6 => ['email' => 'left@example.com', 'customer_id' => 0],
        7 => ['email' => 'both@example.com', 'customer_id' => 8],
        8 => ['email' => 'ticked-again@example.com', 'customer_id' => 0],
    ];

    private const RECORDS = [
        ['subscriber_email' => 'customer@example.com', 'customer_id' => 7, 'subscriber_status' => 1],
        ['subscriber_email' => 'signed-up@example.com', 'customer_id' => 0, 'subscriber_status' => 1],
        ['subscriber_email' => 'old-address@example.com', 'customer_id' => 9, 'subscriber_status' => 3],
        ['subscriber_email' => 'left@example.com', 'customer_id' => 0, 'subscriber_status' => 3],
        ['subscriber_email' => 'both@example.com', 'customer_id' => 0, 'subscriber_status' => 3],
        ['subscriber_email' => 'both-account@example.com', 'customer_id' => 8, 'subscriber_status' => 1],
        ['subscriber_email' => 'ticked-again@example.com', 'customer_id' => 0, 'subscriber_status' => 3],
    ];

    public function testTheConsentModesSendToAShopperWhoAgreedInTheStoreAndCheckEveryOtherWithSmaily(): void
    {
        self::assertSame([
            1 => ReminderConsent::SEND,
            2 => ReminderConsent::SEND,
            3 => ReminderConsent::SEND,
            4 => ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT,
            5 => ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT,
            6 => ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT,
            7 => ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT,
            8 => ReminderConsent::SEND,
        ], $this->consent(true)->verdicts(1, [1, 2], self::CARTS));
    }

    /**
     * A tick of the checkout newsletter box on the cart is a new consent in
     * every mode, also over an earlier unsubscribe in the store (cart 8).
     */
    public function testTheLegitimateInterestModeSendsToEveryoneButAStoreUnsubscribe(): void
    {
        self::assertSame([
            1 => ReminderConsent::SEND,
            2 => ReminderConsent::SEND,
            3 => ReminderConsent::SEND,
            4 => ReminderConsent::SEND,
            5 => ReminderConsent::SKIP_UNSUBSCRIBED,
            6 => ReminderConsent::SKIP_UNSUBSCRIBED,
            7 => ReminderConsent::SKIP_UNSUBSCRIBED,
            8 => ReminderConsent::SEND,
        ], $this->consent(false)->verdicts(1, [1, 2], self::CARTS));
    }

    /**
     * CON-387: the first-order email weighs one customer by the same rule;
     * a tick on the order is a new consent in every mode.
     *
     * @dataProvider oneShopperProvider
     */
    public function testOneShopperIsWeighedByTheSameRule(
        bool $requiresOptin,
        string $email,
        int $customerId,
        bool $ticked,
        string $verdict
    ): void {
        self::assertSame(
            $verdict,
            $this->consent($requiresOptin, false)->verdict(1, [1, 2], $email, $customerId, $ticked)
        );
    }

    /**
     * @return array<string, array{0: bool, 1: string, 2: int, 3: bool, 4: string}>
     */
    public static function oneShopperProvider(): array
    {
        return [
            'consent: a subscriber' => [true, 'customer@example.com', 7, false, ReminderConsent::SEND],
            'consent: a tick' => [true, 'anyone@example.com', 0, true, ReminderConsent::SEND],
            'consent: no record' => [true, 'anyone@example.com', 0, false, ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT],
            'consent: unsubscribed' => [true, 'renamed@example.com', 9, false, ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT],
            'all customers: no record' => [false, 'anyone@example.com', 0, false, ReminderConsent::SEND],
            'all customers: unsubscribed' => [false, 'renamed@example.com', 9, false, ReminderConsent::SKIP_UNSUBSCRIBED],
            'all customers: unsubscribed, a tick' => [false, 'left@example.com', 0, true, ReminderConsent::SEND],
        ];
    }

    public function testNoCartsReadNothing(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        $consent = new ReminderConsent(
            $this->createMock(Mode::class),
            $resource,
            $this->createMock(StateManager::class)
        );

        self::assertSame([], $consent->verdicts(1, [1], []));
    }

    private function consent(bool $requiresOptin, bool $readsTicks = true): ReminderConsent
    {
        $mode = $this->createMock(Mode::class);
        $mode->method('requiresOptin')->with(1)->willReturn($requiresOptin);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('quoteInto')->willReturn('');
        $connection->method('fetchAll')->with($select)->willReturn(array_map(
            static fn (array $row): array => array_map('strval', $row),
            self::RECORDS
        ));
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $stateManager = $this->createMock(StateManager::class);
        $stateManager->expects($readsTicks ? self::once() : self::never())->method('optedInQuotes')
            ->willReturn([2 => true, 8 => true]);

        return new ReminderConsent($mode, $resource, $stateManager);
    }
}
