<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3582: the contact import sends the audience the live sync covers under
 * the store's contact-sync mode, and the estimate counts exactly the rows the
 * import walks — checked against real SQL on a mixed customer and subscriber
 * base.
 */
class ContactAudienceTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 1;
    private const DEFAULT_STORE_ID = 1;

    private SchemaInstaller $schema;

    private ContactAudience $audience;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
        $this->seed();

        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(self::DEFAULT_STORE_ID);
        $website = $this->createMock(Website::class);
        $website->method('getStoreIds')->willReturn(['1' => '1', '2' => '2']);
        $website->method('getDefaultStore')->willReturn($defaultStore);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->with(self::WEBSITE_ID)->willReturn($website);

        $this->audience = new ContactAudience(
            $this->objectManager->get(ResourceConnection::class),
            $storeManager
        );
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        parent::tearDown();
    }

    public function testSubscribersOnlyImportsTheWebsitesSubscribedAndUnsubscribedSubscribers(): void
    {
        self::assertSame(5, $this->audience->count(self::WEBSITE_ID, SyncMode::MODE_CONSENT));
        self::assertSame(
            [
                ['person-subscribed@example.com', 1, true],
                ['person-unsubscribed@example.com', 2, false],
                ['person-guest@example.com', 1, true],
                ['person-guest-left@example.com', 2, false],
                ['person-registered-later@example.com', 1, true],
            ],
            $this->walk(SyncMode::MODE_CONSENT)
        );
    }

    public function testAllCustomersAddsEveryOtherRegisteredCustomerAsNotSubscribed(): void
    {
        self::assertSame(7, $this->audience->count(self::WEBSITE_ID, SyncMode::MODE_LEGITIMATE_INTEREST));
        self::assertSame(
            [
                ['person-subscribed@example.com', 1, true],
                ['person-unsubscribed@example.com', 2, false],
                ['person-guest@example.com', 1, true],
                ['person-guest-left@example.com', 2, false],
                ['person-registered-later@example.com', 1, true],
                // Never subscribed, and a pending confirmation: both go as
                // not subscribed. The admin-created account has no store of
                // its own, so it goes through the website's default store.
                ['person-never@example.com', 2, false],
                ['person-pending@example.com', self::DEFAULT_STORE_ID, false],
            ],
            $this->walk(SyncMode::MODE_LEGITIMATE_INTEREST)
        );
    }

    public function testCheckoutOptInOnlyImportsNobody(): void
    {
        self::assertSame(0, $this->audience->count(self::WEBSITE_ID, SyncMode::MODE_CHECKOUT_OPTIN));
    }

    public function testPagesResumeAfterTheCursor(): void
    {
        $first = $this->audience->subscriberPage(self::WEBSITE_ID, 0, 2);
        $next = $this->audience->subscriberPage(self::WEBSITE_ID, $first[1]['id'], 2);
        self::assertSame('person-guest@example.com', $next[0]['email']);

        $customers = $this->audience->customerPage(self::WEBSITE_ID, 0, 1);
        self::assertSame('person-never@example.com', $customers[0]['email']);
        $more = $this->audience->customerPage(self::WEBSITE_ID, $customers[0]['id'], 1);
        self::assertSame('person-pending@example.com', $more[0]['email']);
        self::assertSame([], $this->audience->customerPage(self::WEBSITE_ID, $more[0]['id'], 1));
    }

    /**
     * Every row the import would send, page by page, as [email, store, subscribed].
     *
     * @return list<array{string, int, bool}>
     */
    private function walk(string $mode): array
    {
        $rows = [];
        $cursor = 0;
        while ($page = $this->audience->subscriberPage(self::WEBSITE_ID, $cursor, 2)) {
            foreach ($page as $row) {
                $rows[] = [$row['email'], $row['store_id'], $row['subscribed']];
                $cursor = $row['id'];
            }
        }
        if ($mode !== SyncMode::MODE_LEGITIMATE_INTEREST) {
            return $rows;
        }
        $cursor = 0;
        while ($page = $this->audience->customerPage(self::WEBSITE_ID, $cursor, 2)) {
            foreach ($page as $row) {
                $rows[] = [$row['email'], $row['store_id'], $row['subscribed']];
                $cursor = $row['id'];
            }
        }

        return $rows;
    }

    private function seed(): void
    {
        // Website 1 = stores 1 and 2; website 2 = store 3.
        foreach ([
            [10, 1, 'person-subscribed@example.com', 1],
            [11, 1, 'person-unsubscribed@example.com', 2],
            [12, 1, 'person-never@example.com', 2],
            [13, 1, 'person-pending@example.com', 0],
            [14, 1, 'person-registered-later@example.com', 1],
            [15, 2, 'person-other-website@example.com', 3],
        ] as [$id, $websiteId, $email, $storeId]) {
            $this->connection->insert('customer_entity', [
                'entity_id' => $id,
                'website_id' => $websiteId,
                'email' => $email,
                'store_id' => $storeId,
            ]);
        }

        foreach ([
            [1, 10, 'person-subscribed@example.com', Subscriber::STATUS_SUBSCRIBED],
            [2, 11, 'person-unsubscribed@example.com', Subscriber::STATUS_UNSUBSCRIBED],
            [1, 13, 'person-pending@example.com', Subscriber::STATUS_NOT_ACTIVE],
            [1, 0, 'person-guest@example.com', Subscriber::STATUS_SUBSCRIBED],
            [2, 0, 'person-guest-left@example.com', Subscriber::STATUS_UNSUBSCRIBED],
            [1, 0, 'person-guest-unconfirmed@example.com', Subscriber::STATUS_UNCONFIRMED],
            // Subscribed as a guest, registered later with the same address
            // and never linked: one contact, sent once, as subscribed.
            [1, 0, 'person-registered-later@example.com', Subscriber::STATUS_SUBSCRIBED],
            [3, 0, 'person-other-website@example.com', Subscriber::STATUS_SUBSCRIBED],
        ] as [$storeId, $customerId, $email, $status]) {
            $this->connection->insert('newsletter_subscriber', [
                'store_id' => $storeId,
                'customer_id' => $customerId,
                'subscriber_email' => $email,
                'subscriber_status' => $status,
            ]);
        }
    }
}
