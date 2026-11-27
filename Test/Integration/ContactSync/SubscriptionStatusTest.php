<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\ContactSync;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\CustomerSearchResultsInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Newsletter\Helper\Data as NewsletterHelper;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\CustomerSubscriberCache;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\SubscriptionManager;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Backfill\ContactsProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\Event as QueueEvent;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\ContactSync\UnconnectedSignup;
use Smaily\Connect\Observer\CustomerSaveAfter;
use Smaily\Connect\Observer\SubscriberSaveAfter;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-195, CON-196: a subscription status goes to Smaily only when the
 * status itself changes. A change of the newsletter status is a consent act
 * whoever makes it — the shopper, staff in the admin, an API call, a Magento
 * import — and sends 0 or 1, so a subscription subscribes a contact who
 * unsubscribed in Smaily again. The extension's contacts import and a profile
 * save send a subscribed contact without is_unsubscribed, so Smaily keeps the
 * status it holds and creates a new contact as subscribed. On real
 * newsletter_subscriber rows, the real queue and Magento's own subscriber
 * model; the Smaily client is stubbed.
 *
 * PRO-4322: the status change is read from the saved status itself, not
 * only from the subscriber's status-changed flag, which code that sets the
 * status field directly leaves unset. Magento's own newsletter form, its
 * confirmation link and its unsubscribe link run here through Magento's
 * SubscriptionManager and subscriber model, unchanged.
 *
 * CON-477: the welcome follows the shopper's own storefront subscription,
 * not contact sync. With contact sync off, or in a mode that leaves the
 * signup out, the welcome row is queued alone and carries the contact's
 * language; with contact sync on, it is queued after the contact sync and
 * leaves the language to it.
 */
class SubscriptionStatusTest extends IntegrationTestCase
{
    private SchemaInstaller $schema;

    private StoreManagerInterface $storeManager;

    private string $syncMode = SyncMode::MODE_CONSENT;

    private string $area = 'adminhtml';

    private bool $connected = true;

    private bool $syncActive = true;

    private bool $welcome = false;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/SubscriberFactory.php';
        require_once __DIR__ . '/../Support/Stub/CustomerInterfaceFactory.php';

        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
        $this->storeManager = $this->storeManager();
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        parent::tearDown();
    }

    /**
     * @dataProvider importingModes
     */
    public function testTheContactsImportNeverResubscribesAContact(string $mode): void
    {
        $this->syncMode = $mode;
        $this->seedSubscriber('subscribed@example.com', Subscriber::STATUS_SUBSCRIBED);
        $this->seedSubscriber('left@example.com', Subscriber::STATUS_UNSUBSCRIBED);

        $posted = [];
        $client = $this->createMock(SmailyClient::class);
        $client->method('post')->willReturnCallback(
            static function (string $endpoint, array $contacts) use (&$posted): array {
                $posted = array_merge($posted, $contacts);

                return [];
            }
        );
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn($client);

        /** @var JobManager $jobManager */
        $jobManager = $this->objectManager->create(JobManager::class);
        $job = $jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->contactsProcessor($jobManager, $clientProvider)->process($job);

        self::assertSame([
            ['email' => 'subscribed@example.com'],
            ['email' => 'left@example.com', 'is_unsubscribed' => 1],
        ], $this->fields($posted));
    }

    /**
     * CON-432: a contact Smaily refuses during the import counts as failed on
     * the job and is kept in the Log as a failed contact sync with Smaily's
     * code, never its text; Send again from the Log puts it back in the
     * queue. The contacts Smaily took are not in the Log.
     */
    public function testAContactSmailyRefusesInTheImportIsKeptInTheLogWithItsReason(): void
    {
        $this->seedSubscriber('good@example.com', Subscriber::STATUS_SUBSCRIBED);
        $this->seedSubscriber('bad@example.com', Subscriber::STATUS_SUBSCRIBED);

        $client = $this->createMock(SmailyClient::class);
        $client->method('post')->willReturnCallback(static function (string $endpoint, array $contacts): array {
            if (count($contacts) > 1 || $contacts[0]['email'] === 'bad@example.com') {
                throw new ApiException('Smaily API returned code 203: Invalid data', 203);
            }

            return [];
        });
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturn($client);

        /** @var JobManager $jobManager */
        $jobManager = $this->objectManager->create(JobManager::class);
        $job = $jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $this->contactsProcessor($jobManager, $clientProvider)->process($job);

        $stored = $this->fetchRow('smaily_backfill_job', (int)$job->getId());
        self::assertSame(Job::STATUS_COMPLETED, $stored['status']);
        self::assertSame(['1', '1'], [(string)$stored['processed_count'], (string)$stored['failed_count']]);

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(1, $rows, 'Only the refused contact is in the Log');
        self::assertSame(EventType::CONTACT_SYNC, $rows[0]['event_type']);
        self::assertSame(QueueEvent::STATUS_FAILED, $rows[0]['status']);
        self::assertSame('1', (string)$rows[0]['website_id']);
        self::assertStringStartsWith('permanent_envelope_203: ', (string)$rows[0]['last_error']);
        self::assertSame(['bad@example.com'], array_column($this->queuedContacts(), 'email'));
        self::assertSame(
            $this->objectManager->get(ContactEntity::class)->of('bad@example.com'),
            $rows[0]['entity_id']
        );

        /** @var EventQueue $queue */
        $queue = $this->objectManager->get(EventQueue::class);
        self::assertSame(1, $queue->retry([(int)$rows[0]['id']]));
        self::assertSame(QueueEvent::STATUS_PENDING, $this->fetchAll(EventResource::TABLE_NAME)[0]['status']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function importingModes(): array
    {
        return [
            'subscribers only (consent)' => [SyncMode::MODE_CONSENT],
            'all customers (legitimate interest)' => [SyncMode::MODE_LEGITIMATE_INTEREST],
        ];
    }

    /**
     * A profile save changes other fields, not the subscription status, so it
     * sends none — under all customers also for a customer unsubscribed in
     * the store (Erkki, 2026-10-23, superseding PRO-3616).
     *
     * @dataProvider profileSaves
     */
    public function testAnAdminSaveOfACustomerNeverSendsAStatus(string $mode, int $status): void
    {
        $this->syncMode = $mode;
        $this->seedSubscriber('customer@example.com', $status, 7);
        $customer = $this->createConfiguredMock(CustomerInterface::class, [
            'getId' => 7,
            'getEmail' => 'customer@example.com',
            'getStoreId' => 1,
            'getWebsiteId' => 1,
            'getAddresses' => [],
        ]);
        $subscriberFactory = $this->createMock(SubscriberFactory::class);
        $subscriberFactory->method('create')->willReturnCallback(fn (): Subscriber => $this->newSubscriber());
        $observer = new CustomerSaveAfter(
            $this->config(),
            new Mode($this->config()),
            new ReconcileGuard(),
            $this->dispatcher(),
            $subscriberFactory,
            $this->createMock(UnconnectedSignup::class)
        );

        $observer->execute(new Observer(['event' => new Event(['customer_data_object' => $customer])]));

        self::assertSame([['email' => 'customer@example.com']], $this->queuedContacts());
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function profileSaves(): array
    {
        return [
            'subscribers only, subscribed' => [SyncMode::MODE_CONSENT, Subscriber::STATUS_SUBSCRIBED],
            'all customers, subscribed' => [SyncMode::MODE_LEGITIMATE_INTEREST, Subscriber::STATUS_SUBSCRIBED],
            'all customers, unsubscribed in the store' => [
                SyncMode::MODE_LEGITIMATE_INTEREST,
                Subscriber::STATUS_UNSUBSCRIBED,
            ],
        ];
    }

    /**
     * @dataProvider subscriptionContexts
     */
    public function testASubscriptionStatusChangeIsSentWhoeverMakesIt(string $area): void
    {
        $this->area = $area;
        $id = $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED);

        $this->changeStatus($id, Subscriber::STATUS_SUBSCRIBED);

        self::assertSame([['email' => 'returning@example.com', 'is_unsubscribed' => 0]], $this->queuedContacts());
    }

    /**
     * @dataProvider subscriptionContexts
     */
    public function testAnUnsubscribeIsSentWhoeverMakesIt(string $area): void
    {
        $this->area = $area;
        $id = $this->seedSubscriber('leaving@example.com', Subscriber::STATUS_SUBSCRIBED);

        $this->changeStatus($id, Subscriber::STATUS_UNSUBSCRIBED);

        self::assertSame([['email' => 'leaving@example.com', 'is_unsubscribed' => 1]], $this->queuedContacts());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function subscriptionContexts(): array
    {
        return [
            'the storefront: newsletter form, My Account' => ['frontend'],
            'the checkout box, placed through the REST API' => ['webapi_rest'],
            'a GraphQL call (any caller)' => ['graphql'],
            'staff in the admin' => ['adminhtml'],
            'a Magento import from cron' => ['crontab'],
        ];
    }

    /**
     * PRO-4322: a guest signs up through the storefront newsletter form
     * ("Need to Confirm" off): one contact sync, subscribed.
     */
    public function testAGuestNewsletterFormSignupIsSentOnce(): void
    {
        $this->area = 'frontend';

        $this->subscriptionManager()->subscribe('guest@example.com', 1);

        self::assertSame([['email' => 'guest@example.com', 'is_unsubscribed' => 0]], $this->queuedContacts());
    }

    /**
     * PRO-4322: a guest who unsubscribed signs up again through the form, and
     * a subscribed guest leaves through the unsubscribe link: one sync each.
     */
    public function testAGuestResubscriptionAndUnsubscribeAreSentOnceEach(): void
    {
        $this->area = 'frontend';
        $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED);
        $this->seedSubscriber('leaving@example.com', Subscriber::STATUS_SUBSCRIBED, 0, 'leavecode');

        $this->subscriptionManager()->subscribe('returning@example.com', 1);
        $this->subscriptionManager()->unsubscribe('leaving@example.com', 1, 'leavecode');

        self::assertSame([
            ['email' => 'returning@example.com', 'is_unsubscribed' => 0],
            ['email' => 'leaving@example.com', 'is_unsubscribed' => 1],
        ], $this->queuedContacts());
    }

    /**
     * PRO-3606, PRO-4322: with "Need to Confirm" on, the signup waits for its
     * confirmation and sends nothing; the confirmation link sends it once.
     */
    public function testADoubleOptInSignupIsSentOnlyWhenConfirmed(): void
    {
        $this->area = 'frontend';
        $this->env->getScopeConfig()->setValue(Subscriber::XML_PATH_CONFIRMATION_FLAG, 1);

        $subscriber = $this->subscriptionManager()->subscribe('pending@example.com', 1);

        self::assertSame(Subscriber::STATUS_NOT_ACTIVE, (int)$subscriber->getSubscriberStatus());
        self::assertSame([], $this->queuedContacts());

        $confirming = $this->newSubscriber($this->observingEventManager(new StorefrontSubscription()));
        $confirming->loadBySubscriberEmail('pending@example.com', 1);
        self::assertTrue($confirming->confirm((string)$subscriber->getSubscriberConfirmCode()));

        self::assertSame([['email' => 'pending@example.com', 'is_unsubscribed' => 0]], $this->queuedContacts());
    }

    /**
     * PRO-4322: signing up again while subscribed, and a save of the
     * subscriber that keeps its status, send nothing more.
     */
    public function testASaveWithoutAStatusChangeSendsNothingMore(): void
    {
        $this->area = 'frontend';
        $this->subscriptionManager()->subscribe('guest@example.com', 1);

        $this->subscriptionManager()->subscribe('guest@example.com', 1);
        $resource = $this->subscriberResource();
        $subscriber = $this->newSubscriber($this->observingEventManager(new StorefrontSubscription()), $resource);
        $subscriber->loadBySubscriberEmail('guest@example.com', 1);
        $resource->save($subscriber->setStatus(Subscriber::STATUS_SUBSCRIBED)->setStoreId(2));

        self::assertSame([['email' => 'guest@example.com', 'is_unsubscribed' => 0]], $this->queuedContacts());
    }

    /**
     * PRO-4322: a status set on the field directly, without the subscriber's
     * status-changed flag, is a status change too.
     */
    public function testAStatusSetWithoutTheChangedFlagIsSent(): void
    {
        $id = $this->seedSubscriber('flagless@example.com', Subscriber::STATUS_UNSUBSCRIBED);
        $resource = $this->subscriberResource();
        $subscriber = $this->newSubscriber($this->observingEventManager(new StorefrontSubscription()), $resource);
        $resource->load($subscriber, $id);

        $resource->save($subscriber->setSubscriberStatus(Subscriber::STATUS_SUBSCRIBED));

        self::assertFalse($subscriber->isStatusChanged());
        self::assertSame([['email' => 'flagless@example.com', 'is_unsubscribed' => 0]], $this->queuedContacts());
    }

    /**
     * PRO-3606, PRO-4322: the mode and connection checks still hold for a
     * newsletter-form signup — in the checkout-opt-in-only mode, or with the
     * store not connected, nothing is sent.
     *
     * @dataProvider signupsThatSendNothing
     */
    public function testANewsletterFormSignupSendsNothingWhereTheSettingsSayNo(string $mode, bool $connected): void
    {
        $this->area = 'frontend';
        $this->syncMode = $mode;
        $this->connected = $connected;

        $this->subscriptionManager()->subscribe('guest@example.com', 1);

        self::assertSame([], $this->queuedContacts());
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function signupsThatSendNothing(): array
    {
        return [
            'checkout opt-in only' => [SyncMode::MODE_CHECKOUT_OPTIN, true],
            'not connected' => [SyncMode::MODE_CONSENT, false],
        ];
    }

    /**
     * CON-477: with contact sync off, a shopper who subscribes on the
     * storefront — the newsletter form, the newsletter box of their account,
     * the checkout opt-in placed through the REST API — gets the welcome. No
     * contact sync is queued, so the welcome carries the language.
     *
     * @dataProvider storefrontSignups
     */
    public function testWithContactSyncOffAStorefrontSignupGetsTheWelcomeWithItsLanguage(string $channel): void
    {
        $this->syncActive = false;
        $this->welcome = true;

        $this->subscribeThrough($channel, 'shopper@example.com');

        self::assertSame([['email' => 'shopper@example.com', 'language' => 'et']], $this->queuedRows());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function storefrontSignups(): array
    {
        return [
            'the newsletter form' => ['form'],
            'the newsletter box of the customer account' => ['account'],
            'the checkout opt-in' => ['checkout'],
        ];
    }

    /**
     * CON-477: in the checkout-opt-in-only mode a newsletter-form signup
     * syncs no contact but gets the welcome, with the language.
     */
    public function testInCheckoutOptInOnlyModeANewsletterFormSignupGetsTheWelcome(): void
    {
        $this->area = 'frontend';
        $this->syncMode = SyncMode::MODE_CHECKOUT_OPTIN;
        $this->welcome = true;

        $this->subscriptionManager()->subscribe('guest@example.com', 1);

        self::assertSame([['email' => 'guest@example.com', 'language' => 'et']], $this->queuedRows());
    }

    /**
     * CON-477: with contact sync on, the welcome is queued after the contact
     * sync, which carries the language; the welcome leaves it out, as before.
     */
    public function testWithContactSyncOnTheWelcomeFollowsTheContactSync(): void
    {
        $this->area = 'frontend';
        $this->welcome = true;

        $this->subscriptionManager()->subscribe('guest@example.com', 1);

        self::assertSame(['contact', ['email' => 'guest@example.com']], $this->queuedRows());
    }

    /**
     * CON-477: with contact sync off and "Need to Confirm" on, the signup
     * queues nothing until the confirmation link, which queues the welcome.
     */
    public function testWithContactSyncOffADoubleOptInSignupGetsTheWelcomeWhenConfirmed(): void
    {
        $this->area = 'frontend';
        $this->syncActive = false;
        $this->welcome = true;
        $this->env->getScopeConfig()->setValue(Subscriber::XML_PATH_CONFIRMATION_FLAG, 1);

        $subscriber = $this->subscriptionManager()->subscribe('pending@example.com', 1);

        self::assertSame(Subscriber::STATUS_NOT_ACTIVE, (int)$subscriber->getSubscriberStatus());
        self::assertSame([], $this->queuedRows());

        $confirming = $this->newSubscriber($this->observingEventManager(new StorefrontSubscription()));
        $confirming->loadBySubscriberEmail('pending@example.com', 1);
        self::assertTrue($confirming->confirm((string)$subscriber->getSubscriberConfirmCode()));

        self::assertSame([['email' => 'pending@example.com', 'language' => 'et']], $this->queuedRows());
    }

    /**
     * CON-477: with contact sync off, a subscription made in the admin,
     * through the API or by an import queues nothing — no welcome, as before.
     *
     * @dataProvider nonStorefrontContexts
     */
    public function testWithContactSyncOffANonStorefrontSubscriptionQueuesNothing(string $area): void
    {
        $this->area = $area;
        $this->syncActive = false;
        $this->welcome = true;
        $id = $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED);

        $this->changeStatus($id, Subscriber::STATUS_SUBSCRIBED);

        self::assertSame([], $this->queuedRows());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonStorefrontContexts(): array
    {
        return [
            'staff in the admin' => ['adminhtml'],
            'a GraphQL call' => ['graphql'],
            'a Magento import from cron' => ['crontab'],
        ];
    }

    /**
     * A subscription through one storefront channel, as Magento makes it: a
     * guest through the newsletter form, customer 7 through the newsletter
     * box of their account, a guest through the checkout opt-in, which
     * OrderPlaced saves through the REST API with the storefront mark.
     */
    private function subscribeThrough(string $channel, string $email): void
    {
        if ($channel === 'form') {
            $this->area = 'frontend';
            $this->subscriptionManager()->subscribe($email, 1);

            return;
        }
        if ($channel === 'account') {
            $this->area = 'frontend';
            $customer = $this->createConfiguredMock(CustomerInterface::class, [
                'getId' => 7,
                'getEmail' => $email,
                'getStoreId' => 1,
                'getWebsiteId' => 1,
            ]);
            $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
            $customerRepository->method('getById')->willReturn($customer);
            $this->subscriptionManager(null, $customerRepository)->subscribeCustomer(7, 1);

            return;
        }

        $this->area = 'webapi_rest';
        $storefrontSubscription = new StorefrontSubscription();
        $manager = $this->subscriptionManager($storefrontSubscription);
        $storefrontSubscription->run(static function () use ($manager, $email): void {
            $manager->subscribe($email, 1);
        });
    }

    /**
     * Change a stored subscriber's status through Magento's subscriber model,
     * its save event dispatched to the module's observer, in the test's area.
     * A REST save is the checkout opt-in, marked as OrderPlaced marks it.
     */
    private function changeStatus(int $subscriberId, int $status): void
    {
        $storefrontSubscription = new StorefrontSubscription();
        $eventManager = $this->observingEventManager($storefrontSubscription);

        $save = function () use ($subscriberId, $status, $eventManager): void {
            $resource = $this->subscriberResource();
            $subscriber = $this->newSubscriber($eventManager, $resource);
            $resource->load($subscriber, $subscriberId);
            $resource->save($subscriber->setStatus($status)->setStatusChanged(true));
        };
        $this->area === 'webapi_rest' ? $storefrontSubscription->run($save) : $save();
    }

    /**
     * An event manager that hands a subscriber save to the module's observer,
     * in the test's area.
     */
    private function observingEventManager(StorefrontSubscription $storefrontSubscription): EventManagerInterface
    {
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn($this->area);
        $observer = new SubscriberSaveAfter(
            $this->config(),
            new Mode($this->config()),
            new ReconcileGuard(),
            $this->dispatcher(),
            $this->payloadBuilder(),
            $this->createMock(CustomerRepositoryInterface::class),
            $appState,
            $storefrontSubscription,
            $this->createMock(UnconnectedSignup::class)
        );
        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data = []) use ($observer): void {
                if ($name === 'newsletter_subscriber_save_after') {
                    $observer->execute(new Observer(['event' => new Event($data)] + $data));
                }
            }
        );

        return $eventManager;
    }

    /**
     * Magento's own SubscriptionManager — what the storefront newsletter
     * form calls — on real subscriber models whose saves reach the observer.
     */
    private function subscriptionManager(
        ?StorefrontSubscription $storefrontSubscription = null,
        ?CustomerRepositoryInterface $customerRepository = null
    ): SubscriptionManager {
        $eventManager = $this->observingEventManager($storefrontSubscription ?? new StorefrontSubscription());
        $resource = $this->subscriberResource();
        $subscriberFactory = $this->createMock(SubscriberFactory::class);
        $subscriberFactory->method('create')->willReturnCallback(
            fn (): Subscriber => $this->newSubscriber($eventManager, $resource)
        );

        return $this->objectManager->create(SubscriptionManager::class, [
            'subscriberFactory' => $subscriberFactory,
            'logger' => $this->createMock(LoggerInterface::class),
            'storeManager' => $this->storeManager,
            'scopeConfig' => $this->env->getScopeConfig(),
            'customerAccountManagement' => $this->createMock(AccountManagementInterface::class),
            'customerRepository' => $customerRepository ?? $this->createMock(CustomerRepositoryInterface::class),
            'customerSubscriberCache' => $this->createMock(CustomerSubscriberCache::class),
        ]);
    }

    private function contactsProcessor(JobManager $jobManager, SmailyClientProvider $clientProvider): ContactsProcessor
    {
        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getList')->willReturn(
            $this->createConfiguredMock(CustomerSearchResultsInterface::class, ['getItems' => []])
        );

        return new ContactsProcessor(
            $jobManager,
            new ContactAudience($this->objectManager->get(ResourceConnection::class), $this->storeManager),
            new Mode($this->config()),
            $customerRepository,
            $searchCriteriaBuilder,
            $this->payloadBuilder(),
            $clientProvider,
            $this->config(),
            $this->createMock(Logger::class),
            $this->objectManager->get(EventQueue::class),
            $this->objectManager->get(ContactEntity::class)
        );
    }

    private function dispatcher(): SyncDispatcher
    {
        return new SyncDispatcher(
            $this->payloadBuilder(),
            $this->createMock(LanguageResolver::class),
            $this->storeManager,
            $this->objectManager->create(EventQueue::class),
            $this->objectManager->create(ContactEntity::class)
        );
    }

    private function payloadBuilder(): SubscriberPayloadBuilder
    {
        $languageResolver = $this->createMock(LanguageResolver::class);
        $languageResolver->method('forStore')->willReturn('et');

        return new SubscriberPayloadBuilder(
            $this->config(),
            $this->storeManager,
            $languageResolver,
            $this->createMock(GroupRepositoryInterface::class)
        );
    }

    /**
     * Contact sync active and connected unless the test says not, in the
     * test's mode, no optional fields, the welcome off unless the test says on.
     */
    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isContactSyncActive')->willReturnCallback(fn (): bool => $this->syncActive);
        $config->method('isWelcomeEnabled')->willReturnCallback(fn (): bool => $this->welcome);
        $config->method('isConnected')->willReturnCallback(fn (): bool => $this->connected);
        $config->method('getSyncMode')->willReturnCallback(fn (): string => $this->syncMode);
        $config->method('getSyncFields')->willReturn([]);

        return $config;
    }

    /**
     * A real subscriber model and resource on the test database.
     */
    private function newSubscriber(
        ?EventManagerInterface $eventManager = null,
        ?SubscriberResource $resource = null
    ): Subscriber {
        $context = $eventManager === null
            ? $this->objectManager->create(ModelContext::class)
            : $this->objectManager->create(ModelContext::class, ['eventDispatcher' => $eventManager]);

        return $this->objectManager->create(Subscriber::class, [
            'context' => $context,
            'newsletterData' => $this->createMock(NewsletterHelper::class),
            'transportBuilder' => $this->createMock(TransportBuilder::class),
            'storeManager' => $this->storeManager,
            'customerSession' => $this->createMock(CustomerSession::class),
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'customerAccountManagement' => $this->createMock(AccountManagementInterface::class),
            'inlineTranslation' => $this->createMock(StateInterface::class),
            'resource' => $resource ?? $this->subscriberResource(),
            'customerFactory' => $this->createMock(CustomerInterfaceFactory::class),
            'dataObjectHelper' => $this->createMock(DataObjectHelper::class),
            'subscriptionManager' => $this->createMock(SubscriptionManagerInterface::class),
        ]);
    }

    private function subscriberResource(): SubscriberResource
    {
        return $this->objectManager->create(SubscriberResource::class, ['storeManager' => $this->storeManager]);
    }

    /**
     * Website 1 with store views 1 and 2.
     */
    private function storeManager(): StoreManagerInterface
    {
        $website = $this->createConfiguredMock(Website::class, [
            'getId' => 1,
            'getName' => 'Main Website',
            'getStoreIds' => [1 => 1, 2 => 2],
        ]);
        $store = $this->createConfiguredMock(Store::class, [
            'getId' => 1,
            'getWebsiteId' => 1,
            'getName' => 'Default Store View',
            'getWebsite' => $website,
        ]);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturn($website);

        return $storeManager;
    }

    private function seedSubscriber(string $email, int $status, int $customerId = 0, ?string $code = null): int
    {
        $this->connection->insert('newsletter_subscriber', [
            'store_id' => 1,
            'customer_id' => $customerId,
            'subscriber_email' => $email,
            'subscriber_status' => $status,
            'subscriber_confirm_code' => $code,
        ]);

        return (int)$this->connection->fetchOne(
            $this->connection->select()->from('newsletter_subscriber', ['MAX(subscriber_id)'])
        );
    }

    /**
     * The queued contact syncs' contacts, in queue order.
     *
     * @return list<array<string, mixed>>
     */
    private function queuedContacts(): array
    {
        $contacts = [];
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            if ($row['event_type'] === EventType::CONTACT_SYNC) {
                $contacts[] = json_decode((string)$row['payload'], true)['contact'];
            }
        }

        return $this->fields($contacts);
    }

    /**
     * The queued rows in queue order: a contact sync as 'contact', a welcome
     * as its address's email and language (the fields these tests read).
     *
     * @return list<array<string, mixed>|string>
     */
    private function queuedRows(): array
    {
        $rows = [];
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            $payload = json_decode((string)$row['payload'], true);
            if ($row['event_type'] === EventType::CONTACT_SYNC) {
                $rows[] = 'contact';
            } elseif ($row['event_type'] === EventType::AUTOMATION_TRIGGER
                && $payload['trigger_type'] === Trigger::WELCOME
            ) {
                $rows[] = array_intersect_key($payload['address'], ['email' => true, 'language' => true]);
            }
        }

        return $rows;
    }

    /**
     * Each contact's address and status fields, the only ones these tests read.
     *
     * @param array<int, array<string, mixed>> $contacts
     * @return list<array<string, mixed>>
     */
    private function fields(array $contacts): array
    {
        return array_values(array_map(
            static fn (array $contact): array => array_intersect_key(
                $contact,
                ['email' => true, 'is_unsubscribed' => true]
            ),
            $contacts
        ));
    }
}
