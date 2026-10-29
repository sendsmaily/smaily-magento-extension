<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\AbandonedCart;

use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;
use Smaily\Connect\Model\AbandonedCart\ReminderConsent;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-243: the reminder's consent check reads the newsletter record of the
 * cart's own address only — in any letter case, a capital with an accent
 * too, while an address that differs by an accent is another shopper's. The
 * point is the database's comparison: the newsletter table's collation
 * ignores accents as well as case.
 *
 * Contacts are synthetic throughout.
 */
class ReminderConsentTest extends IntegrationTestCase
{
    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        parent::tearDown();
    }

    /**
     * In the legitimate-interest mode a store unsubscribe stops the reminder:
     * jane@'s unsubscribe does not stop jäne@'s, and ülle@'s stops the cart
     * that carries ÜLLE@.
     */
    public function testAnUnsubscribeStopsOnlyTheReminderOfItsOwnAddress(): void
    {
        $this->subscriber('jane@example.test', Subscriber::STATUS_UNSUBSCRIBED);
        $this->subscriber('ülle@example.test', Subscriber::STATUS_UNSUBSCRIBED);

        self::assertSame(
            [
                1 => ReminderConsent::SEND,
                2 => ReminderConsent::SKIP_UNSUBSCRIBED,
                3 => ReminderConsent::SKIP_UNSUBSCRIBED,
            ],
            $this->consent(SyncMode::MODE_LEGITIMATE_INTEREST)->verdicts(1, [1, 2], [
                1 => ['email' => 'jäne@example.test', 'customer_id' => 0],
                2 => ['email' => 'ÜLLE@example.test', 'customer_id' => 0],
                3 => ['email' => 'Jane@Example.test', 'customer_id' => 0],
            ])
        );
    }

    /**
     * In the consent mode a store subscription sends the reminder: jane@'s
     * subscription is not jäne@'s consent, while ülle@'s is ÜLLE@'s.
     */
    public function testASubscriptionIsTheConsentOfItsOwnAddressOnly(): void
    {
        $this->subscriber('jane@example.test', Subscriber::STATUS_SUBSCRIBED);
        $this->subscriber('ülle@example.test', Subscriber::STATUS_SUBSCRIBED);

        self::assertSame(
            [
                1 => ReminderConsent::SEND_TO_SUBSCRIBED_CONTACT,
                2 => ReminderConsent::SEND,
                3 => ReminderConsent::SEND,
            ],
            $this->consent(SyncMode::MODE_CONSENT)->verdicts(1, [1, 2], [
                1 => ['email' => 'jäne@example.test', 'customer_id' => 0],
                2 => ['email' => 'ÜLLE@example.test', 'customer_id' => 0],
                3 => ['email' => 'Jane@Example.test', 'customer_id' => 0],
            ])
        );
    }

    private function consent(string $syncMode): ReminderConsent
    {
        $config = $this->createMock(Config::class);
        $config->method('getSyncMode')->willReturn($syncMode);

        return new ReminderConsent(
            new Mode($config),
            $this->objectManager->get(ResourceConnection::class),
            $this->objectManager->create(StateManager::class)
        );
    }

    /**
     * Seed a guest's newsletter record of the website's store view 2.
     */
    private function subscriber(string $email, int $status): void
    {
        $this->connection->insert('newsletter_subscriber', [
            'store_id' => 2,
            'customer_id' => 0,
            'subscriber_email' => $email,
            'subscriber_status' => $status,
        ]);
    }
}
