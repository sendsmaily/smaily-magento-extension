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
    ];

    private const RECORDS = [
        ['subscriber_email' => 'customer@example.com', 'customer_id' => 7, 'subscriber_status' => 1],
        ['subscriber_email' => 'signed-up@example.com', 'customer_id' => 0, 'subscriber_status' => 1],
        ['subscriber_email' => 'old-address@example.com', 'customer_id' => 9, 'subscriber_status' => 3],
        ['subscriber_email' => 'left@example.com', 'customer_id' => 0, 'subscriber_status' => 3],
        ['subscriber_email' => 'both@example.com', 'customer_id' => 0, 'subscriber_status' => 3],
        ['subscriber_email' => 'both-account@example.com', 'customer_id' => 8, 'subscriber_status' => 1],
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
        ], $this->consent(true)->verdicts(1, [1, 2], self::CARTS));
    }

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
        ], $this->consent(false)->verdicts(1, [1, 2], self::CARTS));
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

    private function consent(bool $requiresOptin): ReminderConsent
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
        $stateManager->method('optedInQuotes')->willReturn([2 => true]);
        $stateManager->expects($requiresOptin ? self::once() : self::never())->method('optedInQuotes');

        return new ReminderConsent($mode, $resource, $stateManager);
    }
}
