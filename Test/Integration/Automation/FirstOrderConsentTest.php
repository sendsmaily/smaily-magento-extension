<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\AbandonedCart\ReminderConsent;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Discount\AutomationDiscount;
use Smaily\Connect\Model\Discount\CodeIssuer;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Privacy\AddressKey;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\Event as QueueEvent;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Observer\OrderPlaced;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Model\ContactSync\UnconnectedSignup;

/**
 * CON-387: who gets the first-order email follows the website's
 * contact-sync mode, by the abandoned-cart reminder's rule, on real
 * newsletter_subscriber rows and the real cart tracker. The order goes
 * through the real order-placed observer, the real queue, and the real
 * automation handler and Smaily client, with only the HTTP transport faked;
 * the handler reads the contact in Smaily before every send. Contact sync is
 * off throughout: the email does not depend on it.
 */
class FirstOrderConsentTest extends IntegrationTestCase
{
    /** The website's contact-sync mode. */
    private string $syncMode = SyncMode::MODE_CONSENT;

    /** @var array<int, array{request: Request}> what the fake transport received */
    private array $requests = [];

    private StateManager $stateManager;

    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateManager = $this->objectManager->create(StateManager::class);
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        parent::tearDown();
    }

    /**
     * Criterion 1: under All customers every customer's first order gets
     * the email, also one Smaily does not have, except a contact who
     * unsubscribed in Smaily — and, by the reminder's rule, a customer who
     * unsubscribed in the store, recorded as skipped without a Smaily read.
     */
    public function testUnderAllCustomersEveryFirstOrderButAnUnsubscribeGetsTheEmail(): void
    {
        $this->syncMode = SyncMode::MODE_LEGITIMATE_INTEREST;
        $this->subscriber('left-in-store@example.com', Subscriber::STATUS_UNSUBSCRIBED, 13);

        $this->placeFirstOrder(1, 'anyone@example.com', 11);
        $this->placeFirstOrder(2, 'left-in-smaily@example.com', 12);
        $this->placeFirstOrder(3, 'left-in-store@example.com', 13);

        $stored = $this->rowFor('left-in-store@example.com');
        self::assertSame(QueueEvent::STATUS_SENT, $stored['status']);
        self::assertSame(ReminderConsent::SKIPPED_UNSUBSCRIBED_IN_STORE, $stored['last_error']);
        $this->assertLogShowsSkipped($stored);

        $this->flush([$this->notAContact(), $this->ok(), $this->contact('left-in-smaily@example.com', '1')]);

        self::assertSame(['GET', 'POST', 'GET'], $this->requestMethods());
        $this->assertSentTo(['anyone@example.com']);
        $this->assertSkipped('left-in-smaily@example.com', AutomationHandler::SKIPPED_UNSUBSCRIBED_IN_SMAILY);
    }

    /**
     * Criterion 2: in the consent modes a customer who agreed in the store —
     * a newsletter subscriber of the website (by customer or by address), or
     * a tick of the checkout box on this order — gets the email, also when
     * Smaily does not have them; any other customer only as a subscribed
     * contact in Smaily. Criterion 3: a contact who unsubscribed in Smaily
     * gets none.
     *
     * @dataProvider consentModes
     */
    public function testInTheConsentModesTheEmailFollowsTheRemindersRule(string $mode): void
    {
        $this->syncMode = $mode;
        $this->subscriber('account@example.com', Subscriber::STATUS_SUBSCRIBED, 21);
        $this->subscriber('Signed-Up@example.com', Subscriber::STATUS_SUBSCRIBED);
        $this->subscriber('left-in-smaily@example.com', Subscriber::STATUS_SUBSCRIBED, 25);
        $this->stateManager->setNewsletterOptin(3, 1, 'ticked@example.com', true);

        $this->placeFirstOrder(1, 'subscriber@example.com', 21);
        $this->placeFirstOrder(2, 'signed-up@example.com', 22);
        $this->placeFirstOrder(3, 'ticked@example.com', 23);
        $this->placeFirstOrder(4, 'smaily-subscriber@example.com', 24);
        $this->placeFirstOrder(5, 'left-in-smaily@example.com', 25);
        $this->placeFirstOrder(6, 'unknown@example.com', 26);

        $this->flush([
            $this->notAContact(),
            $this->ok(),
            $this->notAContact(),
            $this->ok(),
            $this->notAContact(),
            $this->ok(),
            $this->contact('smaily-subscriber@example.com', '0'),
            $this->ok(),
            $this->contact('left-in-smaily@example.com', '1'),
            $this->notAContact(),
        ]);

        self::assertSame(
            ['GET', 'POST', 'GET', 'POST', 'GET', 'POST', 'GET', 'POST', 'GET', 'GET'],
            $this->requestMethods()
        );
        $this->assertSentTo([
            'subscriber@example.com',
            'signed-up@example.com',
            'ticked@example.com',
            'smaily-subscriber@example.com',
        ]);
        $this->assertSkipped('left-in-smaily@example.com', AutomationHandler::SKIPPED_UNSUBSCRIBED_IN_SMAILY);
        $this->assertSkipped('unknown@example.com', AutomationHandler::SKIPPED_NO_MARKETING_CONSENT);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function consentModes(): array
    {
        return [
            'subscribers only (consent)' => [SyncMode::MODE_CONSENT],
            'checkout opt-in only' => [SyncMode::MODE_CHECKOUT_OPTIN],
        ];
    }

    /**
     * Criterion 3: when Smaily cannot be read, nothing is posted and the
     * email waits on the queue's retry ladder; the next attempt sends it.
     */
    public function testTheEmailWaitsWhenSmailyCannotBeReadAndGoesOnTheRetry(): void
    {
        $this->syncMode = SyncMode::MODE_LEGITIMATE_INTEREST;
        $this->placeFirstOrder(1, 'unread@example.com', 31);

        $this->flush([new Response(503, [], 'Service unavailable')]);

        self::assertSame(['GET'], $this->requestMethods(), 'Nothing is posted while the answer is not known');
        $row = $this->rowFor('unread@example.com');
        self::assertSame(QueueEvent::STATUS_PENDING, $row['status'], 'Retried on the ladder');
        self::assertSame('1', (string)$row['attempts']);

        $this->clock->travel(3600);
        $this->flush([$this->notAContact(), $this->ok()]);

        self::assertSame(['GET', 'GET', 'POST'], $this->requestMethods());
        self::assertSame(QueueEvent::STATUS_SENT, $this->rowFor('unread@example.com')['status']);
        $this->assertSentTo(['unread@example.com']);
    }

    /**
     * Run the order-placed observer for a customer's first order.
     */
    private function placeFirstOrder(int $quoteId, string $email, int $customerId): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getQuoteId')->willReturn($quoteId);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerEmail')->willReturn($email);
        $order->method('getCustomerId')->willReturn($customerId);
        $order->method('getCustomerIsGuest')->willReturn(0);
        $order->method('getIncrementId')->willReturn('00000000' . $quoteId);
        $order->method('getGrandTotal')->willReturn(49.9);
        $order->method('getOrderCurrencyCode')->willReturn('EUR');

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('order')->willReturn($order);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        $this->observer()->execute($observer);
    }

    private function observer(): OrderPlaced
    {
        $stores = [];
        foreach ([1 => 1, 2 => 1, 3 => 2] as $storeId => $websiteId) {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $store->method('getName')->willReturn('Default Store View');
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn(array_values($stores));
        $storeManager->method('getStore')->willReturnCallback(
            static fn (int $storeId): StoreInterface => $stores[$storeId]
        );
        $storeManager->method('getWebsite')->willReturnCallback(function (int $websiteId) use ($stores): Website {
            $website = $this->createMock(Website::class);
            $website->method('getStoreIds')->willReturn(array_map(
                static fn (StoreInterface $store): string => (string)$store->getId(),
                array_values(array_filter(
                    $stores,
                    static fn (StoreInterface $store): bool => (int)$store->getWebsiteId() === $websiteId
                ))
            ));

            return $website;
        });

        $config = $this->createMock(Config::class);
        $config->method('isConnected')->willReturn(true);
        $config->method('isFirstOrderEnabled')->willReturn(true);
        $config->method('getSyncMode')->willReturnCallback(fn (): string => $this->syncMode);
        // The email does not depend on whether contact sync runs (CON-387).
        $config->method('isContactSyncActive')->willReturn(false);

        $languages = $this->createMock(LanguageResolver::class);
        $languages->method('forStore')->willReturn('en');
        $dispatcher = new SyncDispatcher(
            $this->createMock(SubscriberPayloadBuilder::class),
            $languages,
            $storeManager,
            $this->objectManager->create(EventQueue::class),
            new ContactEntity($this->objectManager->create(AddressKey::class))
        );

        // No earlier order of the customer.
        $orders = $this->createMock(OrderCollection::class);
        $orders->method('addFieldToFilter')->willReturnSelf();
        $orders->method('getSize')->willReturn(0);
        $orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $orderCollectionFactory->method('create')->willReturn($orders);

        $mode = new Mode($config);

        return new OrderPlaced(
            $config,
            $mode,
            $dispatcher,
            $this->stateManager,
            $orderCollectionFactory,
            $storeManager,
            $this->createMock(SubscriptionManagerInterface::class),
            new StorefrontSubscription(),
            new ReminderConsent($mode, $this->objectManager->get(ResourceConnection::class), $this->stateManager),
            $this->createMock(UnconnectedSignup::class)
        );
    }

    /**
     * The queue flush cron with the real automation handler and a real
     * Smaily client; every first-order email matches workflow 7, and only
     * the HTTP transport is faked.
     *
     * @param array<int, Response|\Throwable> $responses
     */
    private function flush(array $responses): void
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->requests));
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack): HttpClient {
                $config['handler'] = $handlerStack;

                return new HttpClient($config);
            }
        );
        /** @var Logger $logger */
        $logger = $this->objectManager->get(Logger::class);
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn(new SmailyClient(
            $httpClientFactory,
            $logger,
            $this->createMock(VerifiedCredentials::class),
            $this->createMock(ConnectionHealth::class),
            'demo',
            'user',
            'secret'
        ));
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturn(new WorkflowMatch(7));

        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([
                EventType::AUTOMATION_TRIGGER => $this->objectManager->create(AutomationHandler::class, [
                    'pushedWorkflow' => $this->noPushedWorkflow(),
                    // No discount code: the automation's switch is off (CON-198).
                    'discount' => $this->objectManager->create(AutomationDiscount::class, [
                        'issuer' => $this->createMock(CodeIssuer::class),
                    ]),
                    'clientProvider' => $clientProvider,
                    'router' => $router,
                    'accountResolver' => $this->createMock(AccountResolver::class),
                    'overrides' => $this->createMock(WebsiteOverrides::class),
                    'storeManager' => $this->createMock(StoreManagerInterface::class),
                    'cartState' => $this->stateManager,
                ]),
            ]),
        ]);
        $cron->execute();
    }

    private function ok(): Response
    {
        return new Response(200, [], '{"code":101,"message":"OK"}');
    }

    /**
     * Smaily's answer to a contact read: the contact with its status.
     */
    private function contact(string $email, string $isUnsubscribed): Response
    {
        return new Response(200, [], (string)json_encode(['email' => $email, 'is_unsubscribed' => $isUnsubscribed]));
    }

    private function notAContact(): Response
    {
        return new Response(200, [], '{"code":206,"message":"Could not find requested email address"}');
    }

    /**
     * Seed a newsletter record of the website's store view 2.
     */
    private function subscriber(string $email, int $status, int $customerId = 0): void
    {
        $this->connection->insert('newsletter_subscriber', [
            'store_id' => 2,
            'customer_id' => $customerId,
            'subscriber_email' => $email,
            'subscriber_status' => $status,
        ]);
    }

    /**
     * The one queue row of an address's first-order email.
     *
     * @return array<string, mixed>
     */
    private function rowFor(string $email): array
    {
        $rows = array_values(array_filter(
            $this->fetchAll(EventResource::TABLE_NAME),
            static fn (array $row): bool => (json_decode((string)$row['payload'], true)['address']['email'] ?? null)
                === $email
        ));
        self::assertCount(1, $rows);
        self::assertSame(
            'first_order',
            json_decode((string)$rows[0]['payload'], true)['trigger_type']
        );

        return $rows[0];
    }

    /**
     * Criterion 4: the email was closed without sending, and the Log shows
     * the row as Skipped with the extension's own reason — never Smaily's
     * text.
     */
    private function assertSkipped(string $email, string $reason): void
    {
        $row = $this->rowFor($email);
        self::assertSame(QueueEvent::STATUS_SENT, $row['status'], 'Closed: retrying cannot change the answer');
        self::assertNull($row['sent_payload'], 'Nothing was sent');
        self::assertSame($reason, $row['last_error']);
        $this->assertLogShowsSkipped($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertLogShowsSkipped(array $row): void
    {
        self::assertNull($row['sent_payload'], 'Nothing was sent');
        self::assertStringNotContainsString('Could not find', (string)$row['last_error']);
        /** @var QueueRowLoader $rowLoader */
        $rowLoader = $this->objectManager->create(QueueRowLoader::class);
        self::assertSame(
            Collection::STATUS_SKIPPED,
            $rowLoader->load('smaily-' . $row['id'])['status'] ?? null,
            'The Log shows the row as Skipped'
        );
    }

    /**
     * The first-order emails posted went to these addresses, each with
     * force_opt_in=false and no is_unsubscribed: the email never
     * re-subscribes a contact who unsubscribed in Smaily.
     *
     * @param string[] $emails
     */
    private function assertSentTo(array $emails): void
    {
        $posted = [];
        foreach ($this->requests as $entry) {
            if ($entry['request']->getMethod() !== 'POST') {
                continue;
            }
            $body = json_decode((string)$entry['request']->getBody(), true);
            self::assertSame(7, $body['autoresponder']);
            self::assertFalse($body['force_opt_in']);
            foreach ($body['addresses'] as $address) {
                self::assertArrayNotHasKey('is_unsubscribed', $address);
                self::assertSame('true', $address['is_first_order']);
                $posted[] = $address['email'];
            }
        }
        self::assertSame($emails, $posted);
    }

    /**
     * @return string[]
     */
    private function requestMethods(): array
    {
        return array_map(static fn (array $entry): string => $entry['request']->getMethod(), $this->requests);
    }
}
