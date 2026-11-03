<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Newsletter\Model\Subscriber;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\ConsentingShopper;
use Smaily\Connect\Model\Engine\CustomerIngest;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-241 (contract v1.20.0 §4): the customers import sends the customers
 * the store's contact sync puts into Smaily — per website, by its sync mode,
 * while its sync is active — and every customer with an order inside the
 * horizon; no other account. Real SQL on a three-website base: website 1
 * "Subscribers only", website 2 "All customers", website 3 "Checkout opt-in
 * only".
 *
 * CON-266: the live customer sync (Engine\CustomerIngest) asks the same
 * rule for one customer, so a save queues exactly the customers the import
 * sends, and a subscription or an order queues a customer who joins.
 *
 * CON-237: the scope is a Campaign Intelligence connection's — its
 * websites' contact-sync groups and the buyers of its store views. Under
 * one store (the connection serves every website) the query is the one it
 * always was.
 */
class EngineCustomerScopeTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private const MONTH = 30 * 86400;

    /** The customers import's page under one store, as the import read it before CON-237. */
    private const ONE_STORE_PAGE_SQL = 'SELECT `e`.`entity_id` FROM `customer_entity` AS `e` WHERE (EXISTS ('
        . 'SELECT `o`.`entity_id` FROM `sales_order` AS `o` WHERE (o.customer_id = e.entity_id)'
        . " AND (o.created_at >= '<horizon>')) OR EXISTS (SELECT `ns`.`subscriber_id`"
        . ' FROM `newsletter_subscriber` AS `ns` WHERE (ns.store_id IN (1, 2)) AND (ns.subscriber_status IN (1, 3))'
        . ' AND (ns.customer_id = e.entity_id OR ns.subscriber_email = e.email)) OR (e.website_id = 2 OR EXISTS ('
        . 'SELECT `ns`.`subscriber_id` FROM `newsletter_subscriber` AS `ns` WHERE (ns.store_id IN (3))'
        . ' AND (ns.subscriber_status IN (1, 3)) AND (ns.customer_id = e.entity_id OR ns.subscriber_email = e.email))))'
        . " AND (e.entity_id > 0) ORDER BY `e`.`entity_id` ASC\n LIMIT 2";

    private SchemaInstaller $schema;

    /** @var array<int, string> sync mode by website */
    private array $modes = [
        1 => SyncMode::MODE_CONSENT,
        2 => SyncMode::MODE_LEGITIMATE_INTEREST,
        3 => SyncMode::MODE_CHECKOUT_OPTIN,
    ];

    /** @var array<int, bool> whether the website's contact sync is active */
    private array $active = [1 => true, 2 => true, 3 => true];

    /** The connection whose scope is read: one store's primary, unless a test sets another. */
    private Connection $scopeConnection;

    private string $horizon;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
        $this->schema->createSalesOrder();
        $this->horizon = $this->clockDate(-60 * self::MONTH);
        $this->scopeConnection = $this->engineConnection(
            0,
            $this->connectedSettings(),
            [1, 2, 3],
            [1, 2, 3, 4],
            1,
            true
        );

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
        self::assertSame(5, $this->scope()->count($this->horizon, $this->scopeConnection));
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
        self::assertSame(2, $this->scope()->count($this->horizon, $this->scopeConnection));
    }

    /**
     * The import and the live sync cannot drift: includes() answers yes for
     * exactly the customers the import's pages walk, in every mode set-up.
     */
    public function testOneCustomerIsInScopeExactlyWhenTheImportSendsThem(): void
    {
        foreach ([[], [1 => SyncMode::MODE_LEGITIMATE_INTEREST], [2 => SyncMode::MODE_CONSENT]] as $modes) {
            $this->modes = $modes + $this->modes;
            $scope = $this->scope();
            $included = array_values(array_filter(
                range(1, 9),
                fn (int $id): bool => $scope->includes($id, $this->horizon, $this->scopeConnection)
            ));

            self::assertSame($this->walk(), $included);
        }
        self::assertFalse($this->scope()->includes(0, $this->horizon, $this->scopeConnection));
        self::assertFalse($this->scope()->includes(99, $this->horizon, $this->scopeConnection), 'No such account');
    }

    /**
     * A save of each account queues the row of exactly the customers the
     * import sends; the others are not queued.
     */
    public function testASaveQueuesOnlyACustomerInScope(): void
    {
        $ingest = $this->customerIngest();
        foreach (range(1, 9) as $id) {
            $ingest->saved($this->customer($id));
        }

        self::assertSame([1, 2, 4, 6, 8], $this->queuedCustomers());
    }

    /**
     * Customer 3 (Subscribers only, never bought) subscribes: the
     * subscription queues the account. Customer 5 (last order 70 months
     * ago) orders again: the order queues the account. Customer 9 stays
     * pending and out of scope.
     */
    public function testACustomerWhoJoinsTheScopeIsQueuedThen(): void
    {
        $ingest = $this->customerIngest();
        $ingest->joined(9);
        self::assertSame([], $this->queuedCustomers());

        $this->subscriber(1, 3, 3, Subscriber::STATUS_SUBSCRIBED);
        $ingest->joined(3);
        $this->order(5, 5, 0);
        $ingest->joined(5);

        self::assertSame([3, 5], $this->queuedCustomers());
    }

    /**
     * Group (c): a save the shopper makes logged in with marketing consent
     * is queued although the account is in neither (a) nor (b).
     */
    public function testAConsentingShoppersOwnSaveIsQueued(): void
    {
        $this->customerIngest(3)->saved($this->customer(3));

        self::assertSame([3], $this->queuedCustomers());
    }

    /**
     * CON-237: separate stores — the primary serves website 1 (store views
     * 1 and 2), the connection of website 2 serves websites 2 and 3 (store
     * views 3 and 4). Each scope holds its websites' contact-sync groups and
     * the buyers of its store views; together they are one store's scope.
     */
    public function testEachConnectionsScopeHoldsItsWebsitesAndItsStoreViewsBuyers(): void
    {
        $this->connection->update('sales_order', ['store_id' => 1], 'entity_id = 1');
        $this->connection->update('sales_order', ['store_id' => 4], 'entity_id = 2');
        [$primary, $second] = $this->separateStores();

        $this->scopeConnection = $primary;
        self::assertSame([1, 2, 4], $this->walk());
        self::assertSame(3, $this->scope()->count($this->horizon, $primary));
        self::assertFalse($this->scope()->includes(8, $this->horizon, $primary), 'A buyer of the other store');

        $this->scopeConnection = $second;
        self::assertSame([6, 8], $this->walk());
        self::assertSame(2, $this->scope()->count($this->horizon, $second));
        self::assertTrue($this->scope()->includes(8, $this->horizon, $second));
    }

    /**
     * CON-237: a save queues the customer for each connection whose scope
     * holds them, with that connection's canonical store view.
     */
    public function testASaveQueuesTheCustomerForTheConnectionWhoseScopeHoldsThem(): void
    {
        $this->connection->update('sales_order', ['store_id' => 1], 'entity_id = 1');
        $this->connection->update('sales_order', ['store_id' => 4], 'entity_id = 2');
        $ingest = $this->customerIngest(0, $this->engineConnections(array_map(
            static fn (Connection $connection): array => [$connection, null],
            $this->separateStores()
        )));
        foreach (range(1, 9) as $id) {
            $ingest->saved($this->customer($id));
        }

        $rows = $this->connection->fetchPairs(
            $this->connection->select()
                ->from('smaily_ingest_queue', ['entity_id', 'store_id'])
                ->where('domain = ?', Client::DOMAIN_CUSTOMERS)
                ->order('id ASC')
        );
        self::assertSame(['1' => '1', '2' => '1', '4' => '1', '6' => '3', '8' => '3'], $rows);
    }

    /**
     * CON-237, the single-connection golden test: under one store the
     * customers import reads its page with the query it always read — no
     * store view or website narrowing.
     */
    public function testOneStoreReadsThePageWithTheQueryItAlwaysRead(): void
    {
        $connection = $this->connection;
        if (!$connection instanceof \Zend_Db_Adapter_Abstract) {
            self::fail('The test connection keeps no query profile');
        }
        $profiler = $connection->getProfiler();
        $profiler->setEnabled(true);
        $profiler->clear();
        $this->scope()->page($this->horizon, 0, 2, $this->scopeConnection);
        $queries = $profiler->getQueryProfiles();
        $profiler->setEnabled(false);

        self::assertIsArray($queries);
        $sql = str_replace($this->horizon, '<horizon>', (string)end($queries)->getQuery());
        self::assertStringNotContainsString('o.store_id', $sql);
        self::assertSame(self::ONE_STORE_PAGE_SQL, $sql);
    }

    /**
     * @return array{0: Connection, 1: Connection} the primary and the connection of website 2
     */
    private function separateStores(): array
    {
        return [
            $this->engineConnection(0, $this->connectedSettings(), [1], [1, 2], 1),
            $this->engineConnection(2, $this->connectedSettings(), [2, 3], [3, 4], 3),
        ];
    }

    private function connectedSettings(): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);

        return $settings;
    }

    private function customerIngest(int $consentingCustomerId = 0, ?Connections $connections = null): CustomerIngest
    {
        $settings = $this->connectedSettings();
        $builder = $this->createMock(CustomerPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static fn (CustomerInterface $customer): array => [
                'email' => (string)$customer->getEmail(),
                'external_id' => (string)$customer->getId(),
            ]
        );
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('horizonNow')->willReturn($this->horizon);
        $shopper = $this->createMock(ConsentingShopper::class);
        $shopper->method('isLoggedInWithConsent')->willReturnCallback(
            static fn (int $id): bool => $id === $consentingCustomerId
        );
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(fn (int $id): CustomerInterface => $this->customer($id));

        return new CustomerIngest(
            $builder,
            $this->objectManager->create(IngestQueue::class),
            $this->scope(),
            $historyImport,
            $shopper,
            $repository,
            $connections ?? $this->oneEngineConnection($settings),
            $this->createMock(StoreManagerInterface::class)
        );
    }

    private function customer(int $id): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn((string)$id);
        $customer->method('getEmail')->willReturn('customer' . $id . '@example.invalid');

        return $customer;
    }

    /**
     * @return int[]
     */
    private function queuedCustomers(): array
    {
        $ids = $this->connection->fetchCol(
            $this->connection->select()
                ->from('smaily_ingest_queue', ['entity_id'])
                ->where('domain = ?', Client::DOMAIN_CUSTOMERS)
                ->order('id ASC')
        );

        return array_map('intval', $ids);
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
        while ($page = $scope->page($this->horizon, $after, 2, $this->scopeConnection)) {
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
