<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Cron;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Model\ResourceModel\Db\VersionControl\Snapshot;
use Magento\Newsletter\Model\Subscriber;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\ResourceModel\Quote as QuoteResource;
use Magento\Quote\Model\ResourceModel\Quote\Collection as QuoteCollection;
use Magento\Quote\Model\ResourceModel\Quote\CollectionFactory as QuoteCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Cron\AbandonedCart;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Cron\QueueJanitor;
use Smaily\Connect\Model\AbandonedCart\PayloadBuilder;
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
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Privacy\AddressKey;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * The abandoned-cart scan against the real cart tracker, the real queue and
 * Magento's own quote collection on the quote and quote_address mirrors.
 * PRO-3693: one address gets at most one reminder in 24 hours, whatever
 * number of carts carry it. PRO-3711: handled carts never keep a new one
 * out of the scan's page. The payload builder is stubbed: it reads the
 * cart's own email. PRO-1960: the reminders are built once per store, under
 * one emulation of that store. CON-195: who gets a reminder follows the
 * website's contact-sync mode, on real newsletter_subscriber rows; the
 * reminders go through the real automation handler and Smaily client, with
 * only the HTTP transport faked.
 */
class AbandonedCartTest extends IntegrationTestCase
{
    private const CART_TABLE = 'smaily_abandoned_cart';

    private StateManager $stateManager;

    /** @var array<int, int[]> The quote ids of each payload build, in build order. */
    private array $builds = [];

    /** The website's contact-sync mode. */
    private string $syncMode = SyncMode::MODE_CONSENT;

    /** @var array<int, array{request: Request}> what the fake transport received */
    private array $requests = [];

    private SchemaInstaller $schema;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/QuoteCollectionFactory.php';
        $this->stateManager = $this->objectManager->create(StateManager::class);
        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createQuote();
        $this->schema->createQuoteAddress();
        $this->schema->createContactTables();
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        $this->connection->query('DROP TABLE IF EXISTS `quote_address`');
        $this->connection->query('DROP TABLE IF EXISTS `quote`');
        parent::tearDown();
    }

    public function testASecondCartOfARemindedAddressIsSkippedAndTheLogSaysWhy(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(3 * 3600);

        $this->idleCarts([2 => 'Shopper@Example.com', 3 => 'other@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(2), 'Compared in any case');
        self::assertNull($this->fetchRow(self::CART_TABLE, 2, 'quote_id')['mail_sent_at']);
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(3));

        $rows = $this->queueRowsByEntity();
        self::assertSame(Event::STATUS_PENDING, $rows['other@example.com']['status']);
        $skipped = $rows['Shopper@Example.com'];
        self::assertSame(Event::STATUS_SENT, $skipped['status']);
        self::assertNull($skipped['sent_payload'], 'Nothing was sent');
        self::assertSame(AbandonedCart::SKIPPED_RECENTLY_REMINDED, $skipped['last_error']);
        self::assertSame(
            Collection::STATUS_SKIPPED,
            $this->objectManager->create(QueueRowLoader::class)->load('smaily-' . $skipped['id'])['status'] ?? null,
            'The Log shows the row as Skipped'
        );
        $claimed = $this->objectManager->create(EventQueue::class)->claimBatch();
        self::assertSame(
            ['other@example.com'],
            array_map(fn (Event $event): string => (string)$event->getData('entity_id'), $claimed),
            'The flusher never sends the skipped row'
        );
    }

    public function testTwoCartsOfOneAddressInOneRunGetOneReminder(): void
    {
        $this->idleCarts([5 => 'twice@example.com', 6 => 'TWICE@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(5));
        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(6));
    }

    public function testAReminderOlderThan24HoursDoesNotHoldTheNextOneBack(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(86400 + 60);

        $this->idleCarts([2 => 'shopper@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(2));
    }

    public function testASkippedCartIsNotWeighedAgainAndDoesNotCountAsAReminder(): void
    {
        $this->stateManager->markMailed(1, 1, 'shopper@example.com');
        $this->clock->travel(3600);
        $this->idleCarts([2 => 'shopper@example.com']);
        $this->cron()->execute();
        $this->cron()->execute();

        self::assertCount(1, $this->fetchAll(EventResource::TABLE_NAME), 'The skip is logged once');

        // The first reminder ages out; the skip did not start a new 24 hours.
        $this->clock->travel(86400);
        $this->idleCarts([2 => 'shopper@example.com', 3 => 'shopper@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(2), 'A skipped cart stays skipped');
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(3));
    }

    /**
     * PRO-3711: the scan reads one page of 100 carts. Handled carts stay
     * active and idle, so with more of them than a page holds a new cart was
     * never loaded. Each terminal status counts as handled; an open row (the
     * checkout opt-in) does not.
     */
    public function testANewCartIsRemindedWhenMoreHandledCartsThanOnePageHoldsAreIdle(): void
    {
        $statuses = [
            StateManager::STATUS_MAILED,
            StateManager::STATUS_SKIPPED,
            StateManager::STATUS_COMPLETED,
            StateManager::STATUS_ERASED,
            StateManager::STATUS_EXPIRED,
        ];
        $handled = [];
        $tracked = [];
        for ($quoteId = 1; $quoteId <= 150; $quoteId++) {
            $handled[$quoteId] = 'handled-' . $quoteId . '@example.com';
            $tracked[] = [
                'quote_id' => $quoteId,
                'store_id' => 1,
                'email' => $handled[$quoteId],
                'status' => $statuses[$quoteId % count($statuses)],
            ];
        }
        $this->idleCarts($handled);
        $this->connection->insertMultiple(self::CART_TABLE, $tracked);
        $this->idleCarts([151 => 'new@example.com', 152 => 'opted-in@example.com']);
        $this->stateManager->setNewsletterOptin(152, 1, 'opted-in@example.com', true);

        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(151), 'The new cart is reminded');
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(152), 'An open row is not handled');
        self::assertSame(
            ['new@example.com', 'opted-in@example.com'],
            array_keys($this->queueRowsByEntity()),
            'No handled cart is weighed again'
        );
    }

    /**
     * PRO-3730: the page holds the oldest carts by id, whatever order they
     * were last changed in — a newer cart changed earlier waits for the next
     * run, as before the scan stopped walking the whole cart table.
     */
    public function testThePageHoldsTheOldestCartsByIdWhateverOrderTheyChangedIn(): void
    {
        $rows = [];
        for ($quoteId = 1; $quoteId <= 101; $quoteId++) {
            $rows[] = [
                'entity_id' => $quoteId,
                'store_id' => 1,
                'is_active' => 1,
                'items_count' => 1,
                'customer_email' => 'cart-' . $quoteId . '@example.com',
                'updated_at' => $this->clockDate(-3600 - $quoteId * 60),
            ];
        }
        $this->connection->insertMultiple('quote', $rows);

        $this->cron()->execute();

        self::assertSame(
            range(1, 100),
            array_map('intval', array_column($this->fetchAll(self::CART_TABLE, 'quote_id'), 'quote_id'))
        );
    }

    public function testRemindersAreBuiltOncePerStoreAndStillWeighedOldestCartFirst(): void
    {
        $carts = [5 => [2, 'both@example.com'], 6 => [1, 'both@example.com'], 7 => [2, 'other@example.com']];
        $rows = [];
        foreach ($carts as $quoteId => [$storeId, $email]) {
            $rows[] = [
                'entity_id' => $quoteId,
                'store_id' => $storeId,
                'is_active' => 1,
                'items_count' => 1,
                'customer_email' => $email,
                'updated_at' => $this->clockDate(-3600),
            ];
        }
        $this->connection->insertMultiple('quote', $rows);
        $emulated = [];
        $emulation = $this->createMock(Emulation::class);
        $emulation->method('startEnvironmentEmulation')->willReturnCallback(
            function (int $storeId) use (&$emulated): void {
                $emulated[] = $storeId;
            }
        );
        $emulation->expects(self::exactly(2))->method('stopEnvironmentEmulation');

        $this->cron($emulation)->execute();

        self::assertSame([2, 1], $emulated, 'One emulation per store');
        self::assertSame([[5, 7], [6]], $this->builds, 'One build per store');
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(5), 'The older cart of the address');
        self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf(6));
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(7));
    }

    /**
     * PRO-4008: the retention sweep keeps an erased marker past its 30 days
     * while the cart is active, and the cart, still holding the erased
     * address, changing again gets no reminder.
     */
    public function testAnActiveCartKeepsItsErasedMarkerAndChangingAgainSendsNoReminder(): void
    {
        $this->idleCarts([8 => 'erased-shopper@example.com']);
        $this->connection->insert(self::CART_TABLE, [
            'quote_id' => 8,
            'store_id' => 1,
            'email' => null,
            'status' => StateManager::STATUS_ERASED,
        ]);
        $this->connection->update(
            self::CART_TABLE,
            ['updated_at' => $this->clockDate(-31 * 86400)],
            ['quote_id = ?' => 8]
        );

        $this->objectManager->create(QueueJanitor::class)->execute();
        self::assertSame(StateManager::STATUS_ERASED, $this->statusOf(8), 'The marker of the active cart stays');

        // The shopper changes the cart again; it idles past the cutoff.
        $this->clock->travel(86400);
        $this->idleCarts([8 => 'erased-shopper@example.com']);
        $this->cron()->execute();

        self::assertSame(StateManager::STATUS_ERASED, $this->statusOf(8));
        self::assertSame([], $this->builds, 'The cart is not weighed for a reminder');
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME), 'No reminder is queued');
    }

    /**
     * CON-195: in the consent and checkout opt-in modes a shopper who agreed
     * to marketing emails in the store gets the reminder without a contact
     * read: a subscribed customer, a guest who ticked the checkout newsletter
     * box on the cart (no order placed) and a guest who signed up to the
     * newsletter.
     *
     * @dataProvider consentModes
     */
    public function testInTheConsentModesAShopperWhoAgreedInTheStoreGetsTheReminder(string $mode): void
    {
        $this->syncMode = $mode;
        $this->customerCart(1, 'customer@example.com', 7);
        $this->subscriber('customer@example.com', Subscriber::STATUS_SUBSCRIBED, 7);
        $this->idleCarts([2 => 'ticked@example.com', 3 => 'signed-up@example.com']);
        $this->stateManager->setNewsletterOptin(2, 1, 'ticked@example.com', true);
        $this->subscriber('Signed-Up@example.com', Subscriber::STATUS_SUBSCRIBED);

        $this->cron()->execute();

        foreach ([1, 2, 3] as $quoteId) {
            self::assertSame(StateManager::STATUS_MAILED, $this->statusOf($quoteId));
        }
        $this->flush([$this->ok(), $this->ok(), $this->ok()]);
        self::assertSame(['POST', 'POST', 'POST'], $this->requestMethods(), 'No contact read');
        $this->assertRemindersNeverResubscribe(['customer@example.com', 'ticked@example.com', 'signed-up@example.com']);
    }

    /**
     * CON-195: in the consent and checkout opt-in modes a shopper without
     * store consent gets the reminder when the workflow's Smaily account has
     * them as a subscribed contact; the queue reads the contact first.
     *
     * @dataProvider consentModes
     */
    public function testInTheConsentModesASubscribedContactInSmailyGetsTheReminder(string $mode): void
    {
        $this->syncMode = $mode;
        $this->idleCarts([4 => 'smaily-subscriber@example.com']);
        $this->stateManager->setNewsletterOptin(4, 1, 'smaily-subscriber@example.com', false);

        $this->cron()->execute();
        $this->flush([
            new Response(200, [], '{"email":"smaily-subscriber@example.com","is_unsubscribed":"0"}'),
            $this->ok(),
        ]);

        self::assertSame(['GET', 'POST'], $this->requestMethods());
        self::assertStringContainsString('email=smaily-subscriber%40example.com', (string)$this->requests[0]['request']->getUri());
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(4));
        $this->assertRemindersNeverResubscribe(['smaily-subscriber@example.com']);
    }

    /**
     * CON-195: in the consent and checkout opt-in modes every other cart is
     * skipped: Smaily does not have the shopper, or has them unsubscribed.
     * Nothing is posted, the Log shows the row as Skipped with the reason,
     * and the cart is closed as skipped without counting as reminded — the
     * shopper's next cart is weighed again, not held back as reminded.
     *
     * @dataProvider consentModes
     */
    public function testInTheConsentModesEveryOtherCartIsSkippedAndTheLogSaysWhy(string $mode): void
    {
        $this->syncMode = $mode;
        $this->idleCarts([5 => 'unknown@example.com', 6 => 'left@example.com']);

        $this->cron()->execute();
        $this->flush([
            new Response(200, [], '{"code":206,"message":"Could not find requested email address"}'),
            new Response(200, [], '{"email":"left@example.com","is_unsubscribed":"1"}'),
        ]);

        self::assertSame(['GET', 'GET'], $this->requestMethods(), 'Nothing is posted');
        /** @var QueueRowLoader $rowLoader */
        $rowLoader = $this->objectManager->create(QueueRowLoader::class);
        foreach ($this->queueRowsByEntity() as $row) {
            self::assertSame(Event::STATUS_SENT, $row['status'], 'Closed: retrying cannot change the answer');
            self::assertNull($row['sent_payload'], 'Nothing was sent');
            self::assertSame(AutomationHandler::SKIPPED_NO_MARKETING_CONSENT, $row['last_error']);
            self::assertSame(
                Collection::STATUS_SKIPPED,
                $rowLoader->load('smaily-' . $row['id'])['status'] ?? null,
                'The Log shows the row as Skipped'
            );
        }
        foreach ([5, 6] as $quoteId) {
            self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf($quoteId));
            self::assertNull($this->fetchRow(self::CART_TABLE, $quoteId, 'quote_id')['mail_sent_at']);
        }

        $this->idleCarts([7 => 'unknown@example.com']);
        $this->cron()->execute();

        $last = $this->fetchAll(EventResource::TABLE_NAME);
        $last = end($last);
        self::assertIsArray($last);
        self::assertSame(Event::STATUS_PENDING, $last['status'], 'Weighed again, not held back as reminded');
        self::assertSame(7, json_decode((string)$last['payload'], true)[AutomationHandler::SUBSCRIBED_CONTACT_CART]);
    }

    public function testAReminderWithoutStoreConsentWaitsWhenSmailyCannotBeRead(): void
    {
        $this->idleCarts([8 => 'unread@example.com']);

        $this->cron()->execute();
        $this->flush([new Response(503, [], 'Service unavailable')]);

        self::assertSame(['GET'], $this->requestMethods(), 'Nothing is posted while the answer is not known');
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_PENDING, $row['status'], 'Retried on the ladder');
        self::assertSame('1', (string)$row['attempts']);
        self::assertSame(StateManager::STATUS_MAILED, $this->statusOf(8));
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
     * CON-195: in the legitimate-interest mode every cart with an email gets
     * the reminder without a contact read — no newsletter record, a pending
     * confirmation, a subscriber — except a shopper unsubscribed in the
     * store, by customer or by address, whose cart is skipped with the
     * reason in the Log.
     */
    public function testInTheLegitimateInterestModeEveryCartButAStoreUnsubscribeGetsTheReminder(): void
    {
        $this->syncMode = SyncMode::MODE_LEGITIMATE_INTEREST;
        $this->idleCarts([1 => 'anyone@example.com', 2 => 'pending@example.com', 3 => 'subscriber@example.com']);
        $this->subscriber('pending@example.com', Subscriber::STATUS_NOT_ACTIVE);
        $this->subscriber('subscriber@example.com', Subscriber::STATUS_SUBSCRIBED);
        $this->customerCart(4, 'renamed@example.com', 9);
        $this->subscriber('old-address@example.com', Subscriber::STATUS_UNSUBSCRIBED, 9);
        $this->idleCarts([5 => 'left-guest@example.com']);
        $this->subscriber('left-guest@example.com', Subscriber::STATUS_UNSUBSCRIBED);

        $this->cron()->execute();

        $rows = $this->queueRowsByEntity();
        foreach (['renamed@example.com' => 4, 'left-guest@example.com' => 5] as $email => $quoteId) {
            self::assertSame(StateManager::STATUS_SKIPPED, $this->statusOf($quoteId));
            self::assertSame(Event::STATUS_SENT, $rows[$email]['status']);
            self::assertNull($rows[$email]['sent_payload'], 'Nothing was sent');
            self::assertSame(AbandonedCart::SKIPPED_UNSUBSCRIBED_IN_STORE, $rows[$email]['last_error']);
        }
        $this->flush([$this->ok(), $this->ok(), $this->ok()]);
        self::assertSame(['POST', 'POST', 'POST'], $this->requestMethods(), 'No contact read');
        $this->assertRemindersNeverResubscribe(['anyone@example.com', 'pending@example.com', 'subscriber@example.com']);
    }

    private function cron(?Emulation $emulation = null): AbandonedCart
    {
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(1);
        $website->method('getStoreIds')->willReturn([1, 2]);
        $website->method('getDefaultStore')->willReturn($store);
        $storeView = $this->createMock(StoreInterface::class);
        $storeView->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);
        $storeManager->method('getStore')->willReturn($storeView);

        $config = $this->createMock(Config::class);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
        $config->method('getAbandonedCutoffMinutes')->willReturn(30);
        $config->method('getSyncMode')->willReturnCallback(fn (): string => $this->syncMode);

        $languages = $this->createMock(LanguageResolver::class);
        $languages->method('forStore')->willReturn('en');
        $dispatcher = new SyncDispatcher(
            $this->createMock(SubscriberPayloadBuilder::class),
            $languages,
            $storeManager,
            $this->objectManager->create(EventQueue::class),
            new ContactEntity($this->objectManager->create(AddressKey::class))
        );

        $payloadBuilder = $this->createMock(PayloadBuilder::class);
        $payloadBuilder->method('buildAll')->willReturnCallback(function (int $storeId, array $quotes): array {
            $addresses = [];
            foreach ($quotes as $quote) {
                $addresses[(int)$quote->getId()] = ['email' => (string)$quote->getData('customer_email')];
            }
            $this->builds[] = array_keys($addresses);

            return $addresses;
        });

        return new AbandonedCart(
            $storeManager,
            $config,
            $this->quoteCollectionFactory(),
            $this->stateManager,
            $payloadBuilder,
            $dispatcher,
            $emulation ?? $this->createMock(Emulation::class),
            $this->clock,
            $this->createMock(Logger::class),
            new ReminderConsent(
                new Mode($config),
                $this->objectManager->get(ResourceConnection::class),
                $this->stateManager
            )
        );
    }

    /**
     * The queue flush cron with the real automation handler and a real
     * Smaily client; every reminder matches workflow 7, and only the HTTP
     * transport is faked.
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
     * @return string[]
     */
    private function requestMethods(): array
    {
        return array_map(static fn (array $entry): string => $entry['request']->getMethod(), $this->requests);
    }

    /**
     * The reminders posted went to these addresses, each with
     * force_opt_in=false and no is_unsubscribed: a reminder never
     * re-subscribes a contact who unsubscribed in Smaily (CON-195,
     * PRO-3577).
     *
     * @param string[] $emails
     */
    private function assertRemindersNeverResubscribe(array $emails): void
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
                $posted[] = $address['email'];
            }
        }
        self::assertSame($emails, $posted);
    }

    /**
     * Seed a signed-in customer's active cart, idle for an hour.
     */
    private function customerCart(int $quoteId, string $email, int $customerId): void
    {
        $this->connection->insert('quote', [
            'entity_id' => $quoteId,
            'store_id' => 1,
            'is_active' => 1,
            'items_count' => 1,
            'customer_id' => $customerId,
            'customer_email' => $email,
            'updated_at' => $this->clockDate(-3600),
        ]);
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
     * Seed active carts with items, idle for an hour, each with its email on
     * the cart. A cart seeded again is idle for an hour again.
     *
     * @param array<int, string> $emailsByQuote
     */
    private function idleCarts(array $emailsByQuote): void
    {
        $rows = [];
        foreach ($emailsByQuote as $quoteId => $email) {
            $rows[] = [
                'entity_id' => $quoteId,
                'store_id' => 1,
                'is_active' => 1,
                'items_count' => 1,
                'customer_email' => $email,
                'updated_at' => $this->clockDate(-3600),
            ];
        }
        $this->connection->insertOnDuplicate('quote', $rows, ['customer_email', 'updated_at']);
    }

    /**
     * Magento's own quote collection on the mirrors — the scan's SQL runs as
     * in production. Only what needs the full application is stubbed: the
     * resource model (it names the table) and the quote objects (built
     * without their constructor, holding only the row).
     */
    private function quoteCollectionFactory(): QuoteCollectionFactory
    {
        $resource = $this->createMock(QuoteResource::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getMainTable')->willReturn('quote');
        $resource->method('getTable')->willReturnArgument(0);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        $entityFactory = $this->createMock(EntityFactoryInterface::class);
        $entityFactory->method('create')->willReturnCallback(function (): Quote {
            $quote = (new \ReflectionClass(Quote::class))->newInstanceWithoutConstructor();
            $quote->setIdFieldName('entity_id');

            return $quote;
        });

        $factory = $this->createMock(QuoteCollectionFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (): QuoteCollection => $this->objectManager->create(QuoteCollection::class, [
                'entityFactory' => $entityFactory,
                'entitySnapshot' => $this->createMock(Snapshot::class),
                'resource' => $resource,
            ])
        );

        return $factory;
    }

    private function statusOf(int $quoteId): string
    {
        return (string)$this->fetchRow(self::CART_TABLE, $quoteId, 'quote_id')['status'];
    }

    /**
     * @return array<string, array<string, mixed>> queue rows by entity id
     */
    private function queueRowsByEntity(): array
    {
        return array_column($this->fetchAll(EventResource::TABLE_NAME), null, 'entity_id');
    }
}
