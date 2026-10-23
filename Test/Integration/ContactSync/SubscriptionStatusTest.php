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
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Backfill\ContactsProcessor;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
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
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
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
 */
class SubscriptionStatusTest extends IntegrationTestCase
{
    private SchemaInstaller $schema;

    private StoreManagerInterface $storeManager;

    private string $syncMode = SyncMode::MODE_CONSENT;

    private string $area = 'adminhtml';

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
            $subscriberFactory
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
     * Change a stored subscriber's status through Magento's subscriber model,
     * its save event dispatched to the module's observer, in the test's area.
     * A REST save is the checkout opt-in, marked as OrderPlaced marks it.
     */
    private function changeStatus(int $subscriberId, int $status): void
    {
        $storefrontSubscription = new StorefrontSubscription();
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
            $storefrontSubscription
        );
        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data = []) use ($observer): void {
                if ($name === 'newsletter_subscriber_save_after') {
                    $observer->execute(new Observer(['event' => new Event($data)] + $data));
                }
            }
        );

        $save = function () use ($subscriberId, $status, $eventManager): void {
            $resource = $this->subscriberResource();
            $subscriber = $this->newSubscriber($eventManager, $resource);
            $resource->load($subscriber, $subscriberId);
            $resource->save($subscriber->setStatus($status)->setStatusChanged(true));
        };
        $this->area === 'webapi_rest' ? $storefrontSubscription->run($save) : $save();
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
            $this->createMock(Logger::class)
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
        return new SubscriberPayloadBuilder(
            $this->config(),
            $this->storeManager,
            $this->createMock(LanguageResolver::class),
            $this->createMock(GroupRepositoryInterface::class)
        );
    }

    /**
     * Contact sync active and connected, in the test's mode, no optional fields.
     */
    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isContactSyncActive')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
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

    private function seedSubscriber(string $email, int $status, int $customerId = 0): int
    {
        $this->connection->insert('newsletter_subscriber', [
            'store_id' => 1,
            'customer_id' => $customerId,
            'subscriber_email' => $email,
            'subscriber_status' => $status,
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
