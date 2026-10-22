<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\ContactSync;

use Magento\Backend\App\Action\Context as BackendContext;
use Magento\Backend\Helper\Data as BackendHelper;
use Magento\Backend\Model\Session as BackendSession;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Newsletter\Controller\Adminhtml\Subscriber\MassDelete;
use Magento\Newsletter\Helper\Data as NewsletterHelper;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\AdminSubscriberDelete;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Observer\SubscriberDeleteAfter;
use Smaily\Connect\Plugin\Adminhtml\MarkSubscriberMassDelete;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-176: Magento's own Delete action of the Newsletter Subscribers grid,
 * run under the module's plugin, deletes real subscriber rows through the
 * subscriber model; the module's observer of the delete (as etc/events.xml
 * wires it) queues each deleted row's contact as an unsubscribe in Smaily.
 * The extension is set up store-wide; website 2 has contact sync off as its
 * own exception, so its row sends nothing. A delete outside the grid's
 * action — a customer account's deletion — sends nothing either.
 */
class AdminSubscriberDeleteTest extends IntegrationTestCase
{
    /** Store view => website. */
    private const STORE_WEBSITES = [1 => 1, 2 => 2];

    private SchemaInstaller $schema;

    private StoreManagerInterface $storeManager;

    private AdminSubscriberDelete $mark;

    private EventManagerInterface $eventManager;

    private SubscriberResource $subscriberResource;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/SubscriberFactory.php';
        require_once __DIR__ . '/../Support/Stub/CustomerInterfaceFactory.php';
        if (!function_exists('__')) {
            require_once dirname(__DIR__, 3) . '/vendor/magento/framework/Phrase/__.php';
        }

        $this->schema = new SchemaInstaller($this->connection);
        $this->schema->createContactTables();
        $this->seedStoreWideSetup();

        $this->storeManager = $this->storeManager();
        $this->subscriberResource = $this->objectManager->create(
            SubscriberResource::class,
            ['storeManager' => $this->storeManager]
        );
        $this->mark = new AdminSubscriberDelete();
        $config = $this->objectManager->create(Config::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, self::STORE_WEBSITES),
        ]);
        $languageResolver = $this->createMock(LanguageResolver::class);
        $languageResolver->method('forStore')->willReturn('et');
        $dispatcher = new SyncDispatcher(
            new SubscriberPayloadBuilder(
                $config,
                $this->storeManager,
                $languageResolver,
                $this->groupRepository()
            ),
            $languageResolver,
            $this->storeManager,
            $this->objectManager->create(EventQueue::class),
            $this->objectManager->create(ContactEntity::class)
        );
        $this->eventManager = $this->eventManager([
            SubscriberDeleteAfter::class => new SubscriberDeleteAfter(
                $config,
                $this->mark,
                $dispatcher,
                $this->customerRepository()
            ),
        ]);
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        parent::tearDown();
    }

    public function testDeletingOneRowInTheGridUnsubscribesItsContact(): void
    {
        $guest = $this->seedSubscriber(1, 'guest@example.com', Subscriber::STATUS_SUBSCRIBED);

        $this->deleteInTheGrid([$guest]);

        self::assertSame([], $this->subscriberIds());
        self::assertSame([
            ['email' => 'guest@example.com', 'is_unsubscribed' => 1, 'store' => 'Store 1'],
        ], $this->queuedContacts());
    }

    public function testAMassDeleteUnsubscribesEveryDeletedRowOfAnActiveWebsite(): void
    {
        $ids = [
            $this->seedSubscriber(1, 'guest@example.com', Subscriber::STATUS_SUBSCRIBED),
            $this->seedSubscriber(1, 'customer@example.com', Subscriber::STATUS_SUBSCRIBED, 7),
            $this->seedSubscriber(1, 'pending@example.com', Subscriber::STATUS_NOT_ACTIVE),
            $this->seedSubscriber(1, 'gone@example.com', Subscriber::STATUS_UNSUBSCRIBED),
            $this->seedSubscriber(2, 'other-website@example.com', Subscriber::STATUS_SUBSCRIBED),
        ];

        $this->deleteInTheGrid($ids);

        self::assertSame([], $this->subscriberIds(), 'Every selected row is deleted');
        $contacts = $this->queuedContacts();
        self::assertSame(
            ['guest@example.com', 'customer@example.com', 'pending@example.com'],
            array_column($contacts, 'email'),
            'An unsubscribed row and a row of a website with contact sync off send nothing'
        );
        self::assertSame([1, 1, 1], array_column($contacts, 'is_unsubscribed'));
        self::assertSame('Mari', $contacts[1]['first_name'] ?? null, "The customer's fields, as an unsubscribe sends");
    }

    public function testADeleteOutsideTheGridSendsNothing(): void
    {
        $id = $this->seedSubscriber(1, 'customer@example.com', Subscriber::STATUS_SUBSCRIBED, 7);

        // As a customer account's deletion does: through the subscriber's resource.
        $subscriber = $this->newSubscriber();
        $this->subscriberResource->load($subscriber, $id);
        $this->subscriberResource->delete($subscriber);

        self::assertSame([], $this->subscriberIds());
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME));
    }

    /**
     * Magento's MassDelete action, run as the admin runs it: through the plugin.
     *
     * @param int[] $ids
     */
    private function deleteInTheGrid(array $ids): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('subscriber')->willReturn(array_map('strval', $ids));
        $helper = $this->createMock(BackendHelper::class);
        $helper->method('getUrl')->willReturn('');
        $context = $this->createConfiguredMock(BackendContext::class, [
            'getRequest' => $request,
            'getResponse' => $this->createMock(HttpResponse::class),
            'getMessageManager' => $this->createMock(MessageManagerInterface::class),
            'getActionFlag' => $this->createMock(ActionFlag::class),
            'getSession' => $this->createMock(BackendSession::class),
            'getHelper' => $helper,
        ]);
        $subscriberFactory = $this->createMock(SubscriberFactory::class);
        $subscriberFactory->method('create')->willReturnCallback(fn (): Subscriber => $this->newSubscriber());
        $controller = new MassDelete($context, $this->createMock(FileFactory::class), $subscriberFactory);

        (new MarkSubscriberMassDelete($this->mark))->aroundExecute($controller, [$controller, 'execute']);
    }

    /**
     * A real subscriber model and resource on the test database, its events
     * dispatched to the module's observers.
     */
    private function newSubscriber(): Subscriber
    {
        return $this->objectManager->create(Subscriber::class, [
            'context' => $this->objectManager->create(ModelContext::class, ['eventDispatcher' => $this->eventManager]),
            'newsletterData' => $this->createMock(NewsletterHelper::class),
            'transportBuilder' => $this->createMock(TransportBuilder::class),
            'storeManager' => $this->storeManager,
            'customerSession' => $this->createMock(CustomerSession::class),
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'customerAccountManagement' => $this->createMock(AccountManagementInterface::class),
            'inlineTranslation' => $this->createMock(StateInterface::class),
            'resource' => $this->subscriberResource,
            'customerFactory' => $this->createMock(CustomerInterfaceFactory::class),
            'dataObjectHelper' => $this->createMock(DataObjectHelper::class),
            'subscriptionManager' => $this->createMock(SubscriptionManagerInterface::class),
        ]);
    }

    /**
     * Dispatches an event to the observers etc/events.xml wires to it.
     *
     * @param array<class-string, ObserverInterface> $observers
     */
    private function eventManager(array $observers): EventManagerInterface
    {
        $events = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        self::assertNotFalse($events);

        return new class ($events, $observers) implements EventManagerInterface {
            /**
             * @param array<class-string, ObserverInterface> $observers
             */
            public function __construct(
                private readonly \SimpleXMLElement $events,
                private readonly array $observers
            ) {
            }

            /**
             * @param string $eventName
             * @param array<string, mixed> $data
             * @return void
             */
            public function dispatch($eventName, array $data = [])
            {
                $path = sprintf('//event[@name="%s"]/observer/@instance', $eventName);
                foreach ($this->events->xpath($path) ?: [] as $instance) {
                    $observer = $this->observers[(string)$instance] ?? null;
                    if ($observer !== null) {
                        $event = new Event($data);
                        $event->setName($eventName);
                        $observer->execute(new Observer(['event' => $event] + $data));
                    }
                }
            }
        };
    }

    private function seedStoreWideSetup(): void
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $rows = [
            ['default', 0, Config::XML_PATH_SUBDOMAIN, 'shop'],
            ['default', 0, Config::XML_PATH_USERNAME, 'api-user'],
            ['default', 0, Config::XML_PATH_PASSWORD, $encryptor->encrypt('secret')],
            ['default', 0, Config::XML_PATH_CONTACTS_CONFIRMED, '1'],
            // Website 2's own exception: contact sync off.
            ['websites', 2, Config::XML_PATH_SYNC_ENABLED, '0'],
        ];
        foreach ($rows as [$scope, $scopeId, $path, $value]) {
            $this->connection->insert('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ]);
        }
    }

    private function seedSubscriber(int $storeId, string $email, int $status, int $customerId = 0): int
    {
        $this->connection->insert('newsletter_subscriber', [
            'store_id' => $storeId,
            'customer_id' => $customerId,
            'subscriber_email' => $email,
            'subscriber_status' => $status,
        ]);

        return (int)$this->connection->fetchOne(
            $this->connection->select()->from('newsletter_subscriber', ['MAX(subscriber_id)'])
        );
    }

    /**
     * @return int[]
     */
    private function subscriberIds(): array
    {
        return array_map('intval', $this->connection->fetchCol(
            $this->connection->select()->from('newsletter_subscriber', ['subscriber_id'])
        ));
    }

    /**
     * The queued contact syncs' contacts, in queue order, each with the
     * fields this test reads.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queuedContacts(): array
    {
        $contacts = [];
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(EventType::CONTACT_SYNC, $row['event_type']);
            $payload = json_decode((string)$row['payload'], true);
            $contact = $payload['contact'];
            $contacts[] = array_intersect_key($contact, array_flip(['email', 'is_unsubscribed', 'store', 'first_name']));
        }

        return $contacts;
    }

    private function storeManager(): StoreManagerInterface
    {
        $stores = [];
        foreach (self::STORE_WEBSITES as $storeId => $websiteId) {
            $website = $this->createConfiguredMock(Website::class, ['getName' => 'Website ' . $websiteId]);
            $stores[$storeId] = $this->createConfiguredMock(Store::class, [
                'getId' => $storeId,
                'getWebsiteId' => $websiteId,
                'getName' => 'Store ' . $storeId,
                'getWebsite' => $website,
            ]);
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(static fn ($storeId) => $stores[(int)$storeId]);

        return $storeManager;
    }

    private function groupRepository(): GroupRepositoryInterface
    {
        $group = $this->createConfiguredMock(GroupInterface::class, ['getCode' => 'General']);

        return $this->createConfiguredMock(GroupRepositoryInterface::class, ['getById' => $group]);
    }

    private function customerRepository(): CustomerRepositoryInterface
    {
        $customer = $this->createConfiguredMock(CustomerInterface::class, [
            'getId' => 7,
            'getFirstname' => 'Mari',
            'getAddresses' => [],
        ]);
        $repository = $this->createMock(CustomerRepositoryInterface::class);
        $repository->method('getById')->with(7)->willReturn($customer);

        return $repository;
    }
}
