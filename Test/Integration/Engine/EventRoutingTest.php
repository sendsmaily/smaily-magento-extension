<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\MarketingConsent;
use Smaily\Connect\Model\Engine\MergeCustomerSender;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\StoreVisitorToken;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\OrderPlacer;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\Event as QueueEvent;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;
use Smaily\Connect\Model\Queue\Handler\ProfilingConsentHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Observer\Engine\CustomerLogin;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-237 step 4: the marketing event queue's engine rows — the login's
 * identity merge and the shopper's profiling choice — routed by Campaign
 * Intelligence connection on a real smaily_event_queue: queued with their
 * connection's website (Engine\Connection::eventWebsiteId(), 0 for the
 * primary) and drained by the real flush cron through each connection's
 * own client. Only the engine clients, the store's opt-out record and
 * Smaily are stubbed.
 *
 * Separate stores here: the primary serves website 1 (store view 1), the
 * connection of website 2 serves store view 2; store view 3 belongs to
 * website 3, which no connection serves. One store: the primary serves
 * every store view.
 */
class EventRoutingTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private const SESSION_ID = '0b6f3c1e-2a4d-4c8e-9f10-5d2b7a9e4c31';

    /** @var list<array{0: string, 1: string}> the engine calls in order: [engine store, call] */
    private array $calls = [];

    /** @var array<string, int> the store's opt-outs, by address */
    private array $optOutRecords = [];

    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
    }

    /**
     * (b) Each login is merged by the connection serving the store view
     * logged in at, and a profiling choice — one record for the
     * installation — goes to every connected connection, each row through
     * its own connection's client.
     */
    public function testEachRowGoesToItsOwnConnection(): void
    {
        $this->login(1, 41);
        $this->login(2, 42);
        $this->consent(1)->setAllowed('person@example.com', false, 2);

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertSame(
            [
                [EventType::ENGINE_IDENTITY_MERGE, '41', '0'],
                [EventType::ENGINE_IDENTITY_MERGE, '42', '2'],
                [EventType::ENGINE_PROFILING_CONSENT, null, '0'],
                [EventType::ENGINE_PROFILING_CONSENT, null, '2'],
            ],
            array_map(
                static fn (array $row): array => [
                    $row['event_type'],
                    $row['event_type'] === EventType::ENGINE_IDENTITY_MERGE ? $row['entity_id'] : null,
                    (string)$row['website_id'],
                ],
                $rows
            )
        );

        $this->flush(1);

        self::assertEqualsCanonicalizing(
            [['ee', 'merge 41'], ['lv', 'merge 42'], ['ee', 'opt-out'], ['lv', 'opt-out']],
            $this->calls
        );
        self::assertSame(
            [QueueEvent::STATUS_SENT],
            array_values(array_unique(array_column($this->fetchAll(EventResource::TABLE_NAME), 'status')))
        );
    }

    /**
     * (c) A website no connection serves queues nothing for the engine, as
     * an unconnected installation does; a row of its website left in the
     * queue reaches no engine store.
     */
    public function testAWebsiteWithoutAConnectionQueuesNothing(): void
    {
        $this->login(3, 43);
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME));

        $this->queue->enqueue(
            EventType::ENGINE_IDENTITY_MERGE,
            ['customer_email' => 'person@example.com', 'customer_external_id' => '43',
                'anon_session_id' => self::SESSION_ID],
            '43',
            3
        );
        $this->flush(3);

        self::assertSame([], $this->calls);
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertNotSame(QueueEvent::STATUS_SENT, $row['status']);
        self::assertSame(Settings::NOT_CONNECTED, $row['last_error']);
    }

    /**
     * (a) One store: every login and every choice is the primary's, with
     * website 0 as every engine row carried before, and goes through the
     * primary's client.
     */
    public function testOneStoreRoutesEveryRowToThePrimary(): void
    {
        $this->login(2, 42, $this->oneStore(2));
        $this->consent(2, $this->oneStore(2))->setAllowed('person@example.com', false, 2);

        self::assertSame(['0', '0'], array_column($this->fetchAll(EventResource::TABLE_NAME), 'website_id'));

        $this->flush(2, $this->oneStore(2));

        self::assertSame([['ee', 'merge 42'], ['ee', 'opt-out']], $this->calls);
    }

    private function login(int $storeId, int $customerId, ?Connections $connections = null): void
    {
        $connections ??= $this->separateStores($storeId);
        $cookies = $this->createMock(CookieManagerInterface::class);
        $cookies->method('getCookie')->willReturnCallback(
            static fn (string $name): ?string => $name === AttributionManager::DEFAULT_COOKIE_SESSION
                ? self::SESSION_ID
                : null
        );
        $consent = $this->createMock(MarketingConsent::class);
        $attribution = new AttributionManager(
            $connections,
            $cookies,
            $this->objectManager->get(ResourceConnection::class),
            $this->createMock(OrderPlacer::class),
            $consent
        );
        $request = $this->createMock(RequestInterface::class);
        $request->method('getModuleName')->willReturn('customer');
        $observer = new CustomerLogin(
            $request,
            $connections,
            $attribution,
            $this->queue,
            $consent,
            $this->createMock(StoreVisitorToken::class),
            $this->createMock(Config::class)
        );

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($customerId);
        $customer->method('getEmail')->willReturn('person@example.com');
        $customer->method('getStoreId')->willReturn($storeId);
        $observer->execute(new Observer(['event' => new Event(['customer' => $customer])]));
    }

    private function consent(int $storeId, ?Connections $connections = null): ProfilingConsent
    {
        $provider = $this->createMock(SmailyClientProvider::class);
        $provider->method('forStore')->willThrowException(new SmailyClientException('Smaily is down'));

        return $this->objectManager->create(ProfilingConsent::class, [
            'smailyClientProvider' => $provider,
            'connections' => $connections ?? $this->separateStores($storeId),
            'optOuts' => $this->optOuts(),
        ]);
    }

    private function flush(int $storeId, ?Connections $connections = null): void
    {
        $connections ??= $this->separateStores($storeId);
        $profiling = $this->createMock(ProfilingConsent::class);
        $profiling->method('isAllowed')->willReturn(true);
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getStoreId')->willReturn(1);
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getById')->willReturn($customer);
        $sender = $this->createMock(MergeCustomerSender::class);
        $sender->expects(self::never())->method('send');

        $handlers = new HandlerPool([
            EventType::ENGINE_IDENTITY_MERGE => new IdentityMergeHandler(
                $this->queue,
                $profiling,
                $repository,
                $this->createMock(Logger::class),
                $sender,
                $this->createMock(IngestQueue::class),
                $connections
            ),
            EventType::ENGINE_PROFILING_CONSENT => new ProfilingConsentHandler(
                $connections,
                $this->queue,
                $this->optOuts(),
                $this->createMock(Logger::class)
            ),
        ]);
        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'eventQueue' => $this->queue,
            'handlerPool' => $handlers,
        ]);
        $cron->execute();
    }

    /**
     * Separate stores: the primary ("ee") serves website 1, the connection
     * of website 2 ("lv") website 2; both connected. $storeId is the store
     * view of the request.
     */
    private function separateStores(int $storeId): Connections
    {
        return $this->engineConnections([
            [$this->engineConnection(0, $this->connected(), [1], [1], 1), $this->client('ee')],
            [$this->engineConnection(2, $this->connected(), [2], [2], 2), $this->client('lv')],
        ], $storeId);
    }

    /** One store: the primary ("ee") serves websites 1–3. */
    private function oneStore(int $storeId): Connections
    {
        return $this->engineConnections([[
            new Connection(Connection::PRIMARY_KEY, $this->connected(), [1, 2, 3], [1, 2, 3], 1, true),
            $this->client('ee'),
        ]], $storeId);
    }

    private function connected(): Settings
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn(true);
        $settings->method('getEngineConfig')->willReturn([]);

        return $settings;
    }

    /** An engine client that records each call under the engine store's name. */
    private function client(string $store): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('identityMerge')->willReturnCallback(function (array $payload) use ($store): array {
            $this->calls[] = [$store, 'merge ' . $payload['customer_external_id']];

            return ['ok' => true];
        });
        $client->method('customerOptOut')->willReturnCallback(function () use ($store): array {
            $this->calls[] = [$store, 'opt-out'];

            return ['ok' => true];
        });

        return $client;
    }

    private function optOuts(): ProfilingOptOuts
    {
        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->method('moment')->willReturnCallback(
            fn (string $email): ?int => $this->optOutRecords[$email] ?? null
        );
        $optOuts->method('record')->willReturnCallback(function (string $email, int $moment): void {
            $this->optOutRecords[$email] = $moment;
        });

        return $optOuts;
    }
}
