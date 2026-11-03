<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\ConsentingShopper;
use Smaily\Connect\Model\Engine\CustomerIngest;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-266 (contract v1.20.0 / v1.23.0 §4): the live customer sync queues a
 * customer only in the Campaign Intelligence customer scope — the import's
 * rule for one customer (groups a and b), or the shopper's own consenting
 * save (group c) — and a customer who joins the scope is queued then.
 *
 * CON-237: per connected connection — its scope, its horizon, its row and
 * canonical store view; group (c) only for the connection of the store
 * view the shopper is on.
 */
class CustomerIngestTest extends TestCase
{
    use EngineConnectionsStub;

    private const HORIZON = '2021-11-02 00:00:00';

    private Settings&MockObject $settings;
    private IngestQueue&MockObject $queue;
    private EngineCustomerScope&MockObject $scope;
    private ConsentingShopper&MockObject $shopper;
    private CustomerRepositoryInterface&MockObject $repository;

    /** @var array<int, bool> scope answer by customer id */
    private array $inScope = [];

    /** @var array<int, bool> group (c) answer by customer id */
    private array $consenting = [];

    /** @var string[] the entity ids queued */
    private array $queued = [];

    /** @var list<int|null> the store view of each row queued */
    private array $queuedAt = [];

    /** @var array<int, int[]>|null the customers in each connection's scope, by connection key; null: $inScope */
    private ?array $inScopeOf = null;

    /** The store view the request is on. */
    private int $currentStoreId = 1;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->queue = $this->createMock(IngestQueue::class);
        $this->queue->method('enqueue')->willReturnCallback(
            function (string $domain, array $payload, ?string $entityId, ?int $storeId = null): bool {
                self::assertSame(Client::DOMAIN_CUSTOMERS, $domain);
                self::assertSame(['external_id' => $entityId], $payload);
                $this->queued[] = (string)$entityId;
                $this->queuedAt[] = $storeId;

                return true;
            }
        );
        $this->scope = $this->createMock(EngineCustomerScope::class);
        $this->scope->method('includes')->willReturnCallback(
            function (int $id, string $horizon, Connection $connection): bool {
                self::assertSame(self::HORIZON . ' #' . $connection->key(), $horizon, 'The connection\'s horizon');

                return $this->inScopeOf === null
                    ? $this->inScope[$id] ?? false
                    : in_array($id, $this->inScopeOf[$connection->key()] ?? [], true);
            }
        );
        $this->shopper = $this->createMock(ConsentingShopper::class);
        $this->shopper->method('isLoggedInWithConsent')->willReturnCallback(
            fn (int $id): bool => $this->consenting[$id] ?? false
        );
        $this->repository = $this->createMock(CustomerRepositoryInterface::class);
        $this->repository->method('getById')->willReturnCallback(
            function (int $id): CustomerInterface {
                if ($id === 404) {
                    throw new NoSuchEntityException();
                }

                return $this->customer($id);
            }
        );
    }

    public function testASaveOfACustomerInScopeQueuesTheRow(): void
    {
        $this->inScope[7] = true;

        $this->ingest()->saved($this->customer(7));

        self::assertSame(['7'], $this->queued);
    }

    public function testASaveOfACustomerOutOfScopeQueuesNothing(): void
    {
        $this->ingest()->saved($this->customer(8));

        self::assertSame([], $this->queued);
    }

    /**
     * Group (c): the shopper's own save, logged in with marketing consent,
     * keeps the engine's row current although (a) and (b) do not hold.
     */
    public function testAConsentingShoppersOwnSaveQueuesTheRow(): void
    {
        $this->consenting[9] = true;

        $this->ingest()->saved($this->customer(9));

        self::assertSame(['9'], $this->queued);
    }

    public function testEveryLaterSaveOfACustomerInScopeQueuesTheAccountAsSaved(): void
    {
        $this->inScope[7] = true;
        $ingest = $this->ingest();

        $ingest->saved($this->customer(7));
        $ingest->saved($this->customer(7));

        self::assertSame(['7', '7'], $this->queued);
    }

    public function testNothingIsQueuedWhileCampaignIntelligenceIsNotConnected(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(false);
        $this->inScope[7] = true;
        $this->scope->expects(self::never())->method('includes');

        $ingest = $this->ingest();
        $ingest->saved($this->customer(7));
        $ingest->joined(7);

        self::assertSame([], $this->queued);
    }

    public function testACustomerWithoutAnEmailIsNotQueued(): void
    {
        $this->inScope[7] = true;
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn('7');
        $customer->method('getEmail')->willReturn(null);

        $this->ingest()->saved($customer);

        self::assertSame([], $this->queued);
    }

    /**
     * A subscription or an order: the account is read and queued when the
     * customer is now in scope.
     */
    public function testACustomerWhoJoinsTheScopeIsQueued(): void
    {
        $this->inScope[5] = true;

        $ingest = $this->ingest();
        $ingest->joined(5);
        $ingest->joined(6);

        self::assertSame(['5'], $this->queued);
    }

    public function testAJoinAfterASaveInTheSameRequestQueuesNoSecondRow(): void
    {
        $this->inScope[5] = true;
        $this->repository->expects(self::never())->method('getById');

        $ingest = $this->ingest();
        $ingest->saved($this->customer(5));
        $ingest->joined(5);
        $ingest->joined(5);

        self::assertSame(['5'], $this->queued);
    }

    public function testAGuestOrADeletedAccountQueuesNothing(): void
    {
        $this->inScope[404] = true;

        $ingest = $this->ingest();
        $ingest->joined(0);
        $ingest->joined(404);

        self::assertSame([], $this->queued);
    }

    /**
     * CON-237: separate stores — a customer is queued for each connection
     * whose scope holds them, with its canonical store view, and for no
     * other.
     */
    public function testACustomerIsQueuedForEachConnectionWhoseScopeHoldsThem(): void
    {
        $this->inScopeOf = [0 => [5], 2 => [5, 7]];

        $ingest = $this->ingest($this->separateStores());
        $ingest->saved($this->customer(7));
        $ingest->joined(5);
        $ingest->joined(5);

        self::assertSame(['7', '5', '5'], $this->queued);
        self::assertSame([4, 1, 4], $this->queuedAt);
    }

    /**
     * CON-237: group (c) holds for the connection of the store view the
     * shopper is on, not for another engine store.
     */
    public function testAConsentingShoppersSaveGoesToTheConnectionOfTheirStoreViewOnly(): void
    {
        $this->inScopeOf = [];
        $this->consenting[9] = true;
        $this->currentStoreId = 4;

        $this->ingest($this->separateStores())->saved($this->customer(9));

        self::assertSame(['9'], $this->queued);
        self::assertSame([4], $this->queuedAt);
    }

    private function ingest(?Connections $connections = null): CustomerIngest
    {
        $builder = $this->createMock(CustomerPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static fn (CustomerInterface $customer): array => ['external_id' => (string)$customer->getId()]
        );
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('horizonNow')->willReturnCallback(
            static fn (Connection $connection): string => self::HORIZON . ' #' . $connection->key()
        );
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturnCallback(fn (): int => $this->currentStoreId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new CustomerIngest(
            $builder,
            $this->queue,
            $this->scope,
            $historyImport,
            $this->shopper,
            $this->repository,
            $connections ?? $this->oneEngineConnection($this->settings),
            $storeManager
        );
    }

    /**
     * Separate stores, both connected: the primary serves website 1 (store
     * view 1), the connection of website 2 serves store view 4.
     */
    private function separateStores(): Connections
    {
        return $this->engineConnections([
            [$this->engineConnection(0, $this->settings, [1], [1], 1), null],
            [$this->engineConnection(2, $this->settings, [2], [4], 4), null],
        ]);
    }

    private function customer(int $id): CustomerInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn((string)$id);
        $customer->method('getEmail')->willReturn('customer' . $id . '@example.com');

        return $customer;
    }
}
