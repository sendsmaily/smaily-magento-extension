<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-241 (contract v1.20.0 §4): the customers import sends the customers
 * the store's contact sync puts into Smaily — per website, by its sync mode,
 * while its sync is active — and every customer with an order inside the
 * horizon; no other account. Real SQL on a three-website base: website 1
 * "Subscribers only", website 2 "All customers", website 3 "Checkout opt-in
 * only".
 */
class EngineCustomerScopeTest extends IntegrationTestCase
{
    private const MONTH = 30 * 86400;

    private SchemaInstaller $schema;

    /** @var array<int, string> sync mode by website */
    private array $modes = [
        1 => SyncMode::MODE_CONSENT,
        2 => SyncMode::MODE_LEGITIMATE_INTEREST,
        3 => SyncMode::MODE_CHECKOUT_OPTIN,
    ];

    /** @var array<int, bool> whether the website's contact sync is active */
    private array $active = [1 => true, 2 => true, 3 => true];

    private string $horizon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
        $this->schema->createSalesOrder();
        $this->horizon = $this->clockDate(-60 * self::MONTH);

        // id => website; then each one's subscriber row and orders.
        foreach ([1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 2, 7 => 3, 8 => 3, 9 => 1] as $id => $websiteId) {
            $this->connection->insert('customer_entity', [
                'entity_id' => $id,
                'website_id' => $websiteId,
                'email' => 'customer' . $id . '@example.invalid',
                'store_id' => $websiteId,
            ]);
        }
        // 1 subscribed by account; 2 unsubscribed, matched by address only;
        // 7 subscribed on website 3; 9's confirmation is pending.
        $this->subscriber(1, 1, 1, Subscriber::STATUS_SUBSCRIBED);
        $this->subscriber(2, 0, 2, Subscriber::STATUS_UNSUBSCRIBED);
        $this->subscriber(4, 7, 7, Subscriber::STATUS_SUBSCRIBED);
        $this->subscriber(1, 9, 9, Subscriber::STATUS_NOT_ACTIVE);
        // 4 bought two months ago, 8 a month ago; 5 only 70 months ago; a
        // guest buyer has no account.
        $this->order(1, 4, -2 * self::MONTH);
        $this->order(2, 8, -1 * self::MONTH);
        $this->order(3, 5, -70 * self::MONTH);
        $this->order(4, 0, -1 * self::MONTH);
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        $this->schema->dropSalesOrder();
        parent::tearDown();
    }

    /**
     * Subscribers only: the website's subscribed and unsubscribed
     * subscribers, not one who never subscribed or whose confirmation is
     * pending. All customers: every account of the website. Checkout opt-in
     * only: no account by the sync, a buyer by the order.
     */
    public function testEachWebsiteSendsWhatItsSyncModePutsIntoSmailyPlusTheBuyers(): void
    {
        self::assertSame([1, 2, 4, 6, 8], $this->walk());
        self::assertSame(5, $this->scope()->count($this->horizon));
    }

    public function testAllCustomersOnTheFirstWebsiteSendsEveryAccountThere(): void
    {
        $this->modes[1] = SyncMode::MODE_LEGITIMATE_INTEREST;

        self::assertSame([1, 2, 3, 4, 5, 6, 8, 9], $this->walk());
    }

    /**
     * A website whose contact sync is not active puts nobody into Smaily:
     * only its buyers go.
     */
    public function testAWebsiteWhoseSyncIsNotActiveSendsOnlyItsBuyers(): void
    {
        $this->active[1] = false;
        $this->active[2] = false;

        self::assertSame([4, 8], $this->walk());
        self::assertSame(2, $this->scope()->count($this->horizon));
    }

    /**
     * The customers in scope, two a page.
     *
     * @return int[]
     */
    private function walk(): array
    {
        $scope = $this->scope();
        $ids = [];
        $after = 0;
        while ($page = $scope->page($this->horizon, $after, 2)) {
            $ids = array_merge($ids, $page);
            $after = max($page);
        }

        return $ids;
    }

    private function scope(): EngineCustomerScope
    {
        $websites = [];
        $storeIds = [1 => ['1', '2'], 2 => ['3'], 3 => ['4']];
        foreach ($storeIds as $websiteId => $stores) {
            $website = $this->createMock(Website::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getStoreIds')->willReturn(array_combine($stores, $stores));
            $websites[$websiteId] = $website;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (int $websiteId): Website => $websites[$websiteId]
        );
        $mode = $this->createMock(Mode::class);
        $mode->method('mode')->willReturnCallback(fn (?int $websiteId): string => $this->modes[(int)$websiteId]);
        $config = $this->createMock(Config::class);
        $config->method('isContactSyncActive')->willReturnCallback(
            fn (int $websiteId): bool => $this->active[$websiteId]
        );
        $resource = $this->objectManager->get(ResourceConnection::class);

        return new EngineCustomerScope(
            $resource,
            $storeManager,
            new ContactAudience($resource, $storeManager),
            $mode,
            $config
        );
    }

    private function subscriber(int $storeId, int $customerId, int $emailOf, int $status): void
    {
        $this->connection->insert('newsletter_subscriber', [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'subscriber_email' => 'customer' . $emailOf . '@example.invalid',
            'subscriber_status' => $status,
        ]);
    }

    private function order(int $id, int $customerId, int $offsetSeconds): void
    {
        $this->connection->insert('sales_order', [
            'entity_id' => $id,
            'increment_id' => (string)(100000000 + $id),
            'state' => Order::STATE_COMPLETE,
            'customer_id' => $customerId ?: null,
            'customer_email' => 'buyer' . $id . '@example.invalid',
            'created_at' => $this->clockDate($offsetSeconds),
        ]);
    }
}
