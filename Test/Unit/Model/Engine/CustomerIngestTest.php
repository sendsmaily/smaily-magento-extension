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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineCustomerScope;
use Smaily\Connect\Model\Backfill\HistoryImport;
use Smaily\Connect\Model\Engine\Client;
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

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isConnected')->willReturn(true);
        $this->queue = $this->createMock(IngestQueue::class);
        $this->queue->method('enqueue')->willReturnCallback(
            function (string $domain, array $payload, ?string $entityId): bool {
                self::assertSame(Client::DOMAIN_CUSTOMERS, $domain);
                self::assertSame(['external_id' => $entityId], $payload);
                $this->queued[] = (string)$entityId;

                return true;
            }
        );
        $this->scope = $this->createMock(EngineCustomerScope::class);
        $this->scope->method('includes')->willReturnCallback(
            function (int $id, string $horizon): bool {
                self::assertSame(self::HORIZON, $horizon);

                return $this->inScope[$id] ?? false;
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

    private function ingest(): CustomerIngest
    {
        $builder = $this->createMock(CustomerPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static fn (CustomerInterface $customer): array => ['external_id' => (string)$customer->getId()]
        );
        $historyImport = $this->createMock(HistoryImport::class);
        $historyImport->method('horizonNow')->willReturn(self::HORIZON);

        return new CustomerIngest(
            $this->settings,
            $builder,
            $this->queue,
            $this->scope,
            $historyImport,
            $this->shopper,
            $this->repository,
            $this->oneEngineConnection($this->settings)
        );
    }

    private function customer(int $id): CustomerInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn((string)$id);
        $customer->method('getEmail')->willReturn('customer' . $id . '@example.com');

        return $customer;
    }
}
