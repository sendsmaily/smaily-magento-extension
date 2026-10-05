<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Queue;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Privacy\Erasure;
use Smaily\Connect\Model\Privacy\LocalEraser;
use Smaily\Connect\Model\Privacy\AddressKey;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3767: a contact-sync or automation row of an address longer than the
 * queue's 64-character entity column stores the contact's keyed hash, not a
 * cut address, so the checks that match a contact's rows by their entity
 * still find them; an ordinary address is stored as it is, and the Log's
 * Entity filter finds it.
 */
class ContactEntityTest extends IntegrationTestCase
{
    private AddressKey $addressKey;
    private SyncDispatcher $dispatcher;
    private string $longAddress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->longAddress = 'u1-' . str_repeat('a', 70) . '@example.invalid';
        $this->addressKey = $this->objectManager->create(AddressKey::class);

        $payloadBuilder = $this->createMock(SubscriberPayloadBuilder::class);
        $payloadBuilder->method('build')->willReturnCallback(static fn (string $email): array => ['email' => $email]);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->dispatcher = new SyncDispatcher(
            $payloadBuilder,
            $this->createMock(LanguageResolver::class),
            $storeManager,
            $this->objectManager->create(EventQueue::class),
            new ContactEntity($this->addressKey)
        );
    }

    public function testALongAddressIsStoredAsItsKeyedHashNotCut(): void
    {
        $this->dispatcher->dispatchContactSync($this->longAddress, 1, false);
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, ['email' => $this->longAddress]);

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->addressKey->of($this->longAddress), $row['entity_id'], $row['event_type']);
            self::assertStringContainsString($this->longAddress, (string)$row['payload'], 'Details shows the address');
        }
    }

    public function testThePurchaseOfALongAddressWithdrawsItsWaitingReminder(): void
    {
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, ['email' => $this->longAddress]);

        $this->dispatcher->dispatchCartPurchase($this->longAddress, 1);

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(1, $rows, 'No purchase marker: the reminder never went out');
        self::assertSame(EventQueue::CANCELLED_RESPONSE, $rows[0]['last_response'], 'The reminder is withdrawn');
    }

    public function testThePurchaseMarkerOfALongAddressFindsItsDeliveredReminder(): void
    {
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, ['email' => $this->longAddress]);
        $this->markDelivered((int)$this->fetchAll(EventResource::TABLE_NAME)[0]['id']);

        $this->dispatcher->dispatchCartPurchase($this->longAddress, 1);

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(2, $rows);
        self::assertSame(EventType::CONTACT_SYNC, $rows[1]['event_type']);
        self::assertSame($this->addressKey->of($this->longAddress), $rows[1]['entity_id']);
    }

    public function testALaterDeliveredReminderOfALongAddressBlocksSendingTheFailedOneAgain(): void
    {
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, ['email' => $this->longAddress]);
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, ['email' => $this->longAddress]);
        [$failed, $later] = array_map('intval', array_column($this->fetchAll(EventResource::TABLE_NAME), 'id'));
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_FAILED, 'attempts' => 1, 'last_error' => 'Connection timed out'],
            ['id = ?' => $failed]
        );
        $this->markDelivered($later);

        /** @var ResendGuard $guard */
        $guard = $this->objectManager->create(ResendGuard::class);
        $row = $this->fetchRow(EventResource::TABLE_NAME, $failed);

        self::assertSame(
            ResendGuard::REASON_SUPERSEDED,
            $guard->refusalReason(Collection::SOURCE_SMAILY, $failed, ['type' => $row['event_type']] + $row)
        );
    }

    public function testTheErasureFindsTheRowsOfALongAddress(): void
    {
        $this->dispatcher->dispatchContactSync($this->longAddress, 1, false);
        $this->dispatcher->dispatchAutomation(Trigger::ABANDONED_CART, 1, ['email' => $this->longAddress]);
        $this->markDelivered((int)$this->fetchAll(EventResource::TABLE_NAME)[1]['id']);

        // The erasure reads the store's carts too.
        $schema = new SchemaInstaller($this->connection);
        $schema->createQuote();
        $schema->createQuoteAddress();
        /** @var LocalEraser $eraser */
        $eraser = $this->objectManager->create(LocalEraser::class);
        try {
            $counts = $eraser->erase(strtoupper($this->longAddress));
        } finally {
            $this->connection->query('DROP TABLE IF EXISTS `quote_address`');
            $this->connection->query('DROP TABLE IF EXISTS `quote`');
        }

        self::assertSame(['removed' => 1, 'anonymised' => 1], $counts['Queued messages']);
        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame(Erasure::PLACEHOLDER, $rows[0]['entity_id']);
    }

    public function testAnOrdinaryAddressIsStoredAsItIsAndTheLogFilterFindsIt(): void
    {
        $this->dispatcher->dispatchContactSync('u2@example.invalid', 1, false);
        $this->dispatcher->dispatchAutomation(Trigger::WELCOME, 1, ['email' => 'u2@example.invalid']);
        $this->dispatcher->dispatchContactSync('u3@example.invalid', 1, false);

        self::assertSame(
            ['u2@example.invalid', 'u2@example.invalid', 'u3@example.invalid'],
            array_column($this->fetchAll(EventResource::TABLE_NAME), 'entity_id')
        );

        /** @var Collection $collection */
        $collection = $this->objectManager->create(Collection::class);
        // The grid's text filter, as Magento\Ui\Component\Filters\Type\Input hands it on.
        $collection->addFieldToFilter('entity_id', ['like' => '%u2@example.invalid%']);
        $ids = array_column($collection->getData(), 'log_id');
        sort($ids);

        self::assertSame(['smaily-1', 'smaily-2'], $ids);
    }

    /**
     * A delivered row, as EventQueue::markSent() leaves it.
     */
    private function markDelivered(int $id): void
    {
        $this->connection->update(
            EventResource::TABLE_NAME,
            ['status' => Event::STATUS_SENT, 'sent_payload' => '{}', 'last_response' => '{"code":101}'],
            ['id = ?' => $id]
        );
    }
}
