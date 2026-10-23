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
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Lock\LockManagerInterface;
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
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * CON-224: a subscriber row deleted in the admin's Newsletter Subscribers
 * grid changes nothing in Smaily or in Campaign Intelligence — a deletion is
 * not a withdrawal of consent, and the contact may come from another source
 * (Erkki, 2026-10-23; supersedes CON-176/CON-187). Magento's own Delete
 * action deletes real subscriber rows through the subscriber model on a
 * fully set-up store; no observer of the module (as etc/events.xml wires
 * them) hears the delete, no plugin of the module wraps the action, and
 * nothing is queued for either. A customer account's deletion sends
 * nothing either.
 */
class AdminSubscriberDeleteTest extends IntegrationTestCase
{
    private SchemaInstaller $schema;

    private StoreManagerInterface $storeManager;

    private SubscriberResource $subscriberResource;

    private ProfilingOptOuts $optOuts;

    /** @var array<string, string[]> Each dispatched event => the module's observers etc/events.xml wires to it. */
    private array $dispatched = [];

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
        $this->schema->createFlag();
        $this->seedStoreWideSetup();

        $store = $this->createConfiguredMock(Store::class, ['getId' => 1, 'getWebsiteId' => 1]);
        $this->storeManager = $this->createConfiguredMock(StoreManagerInterface::class, ['getStore' => $store]);
        $this->subscriberResource = $this->objectManager->create(
            SubscriberResource::class,
            ['storeManager' => $this->storeManager]
        );
        $this->optOuts = $this->objectManager->create(ProfilingOptOuts::class, [
            'lockManager' => $this->createMock(LockManagerInterface::class),
        ]);
    }

    protected function tearDown(): void
    {
        $this->schema->dropContactTables();
        $this->connection->query('DROP TABLE IF EXISTS `flag`');
        parent::tearDown();
    }

    public function testDeletingRowsInTheGridSendsNothing(): void
    {
        $ids = [
            $this->seedSubscriber('guest@example.com', Subscriber::STATUS_SUBSCRIBED),
            $this->seedSubscriber('customer@example.com', Subscriber::STATUS_SUBSCRIBED, 7),
            $this->seedSubscriber('pending@example.com', Subscriber::STATUS_NOT_ACTIVE),
        ];

        $this->deleteInTheGrid($ids);

        self::assertSame([], $this->subscriberIds(), 'Every selected row is deleted');
        $this->assertNothingSent(['guest@example.com', 'customer@example.com', 'pending@example.com']);
    }

    public function testNoPluginOfTheModuleWrapsTheGridsDeleteAction(): void
    {
        $root = dirname(__DIR__, 3) . '/etc';
        foreach (['/di.xml', '/adminhtml/di.xml'] as $file) {
            $di = simplexml_load_file($root . $file);
            self::assertNotFalse($di);
            self::assertSame([], $di->xpath(sprintf('//type[@name="%s"]/plugin', MassDelete::class)) ?: [], $file);
        }
    }

    public function testADeleteOutsideTheGridSendsNothing(): void
    {
        $id = $this->seedSubscriber('customer@example.com', Subscriber::STATUS_SUBSCRIBED, 7);

        // As a customer account's deletion does: through the subscriber's resource.
        $subscriber = $this->newSubscriber();
        $this->subscriberResource->load($subscriber, $id);
        $this->subscriberResource->delete($subscriber);

        self::assertSame([], $this->subscriberIds());
        $this->assertNothingSent(['customer@example.com']);
    }

    /**
     * @param string[] $emails
     */
    private function assertNothingSent(array $emails): void
    {
        self::assertArrayHasKey('newsletter_subscriber_delete_after', $this->dispatched, 'The model fired its delete');
        self::assertSame(
            [],
            array_filter($this->dispatched),
            'No observer of the module hears a subscriber delete'
        );
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME), 'Nothing queued for Smaily or the engine');
        foreach ($emails as $email) {
            self::assertNull($this->optOuts->moment($email), $email . ' is not opted out of profiling');
        }
    }

    /**
     * Magento's MassDelete action, run as the admin runs it.
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

        (new MassDelete($context, $this->createMock(FileFactory::class), $subscriberFactory))->execute();
    }

    /**
     * A real subscriber model and resource on the test database, its events
     * recorded with the module's observers wired to them.
     */
    private function newSubscriber(): Subscriber
    {
        return $this->objectManager->create(Subscriber::class, [
            'context' => $this->objectManager->create(ModelContext::class, ['eventDispatcher' => $this->eventManager()]),
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
     * Records each dispatched event with the observers etc/events.xml wires to it.
     */
    private function eventManager(): EventManagerInterface
    {
        $events = simplexml_load_file(dirname(__DIR__, 3) . '/etc/events.xml');
        self::assertNotFalse($events);
        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(function (string $eventName) use ($events): void {
            $path = sprintf('//event[@name="%s"]/observer/@instance', $eventName);
            $this->dispatched[$eventName] = array_map('strval', $events->xpath($path) ?: []);
        });

        return $eventManager;
    }

    private function seedStoreWideSetup(): void
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $rows = [
            [Config::XML_PATH_SUBDOMAIN, 'shop'],
            [Config::XML_PATH_USERNAME, 'api-user'],
            [Config::XML_PATH_PASSWORD, $encryptor->encrypt('secret')],
            [Config::XML_PATH_CONTACTS_CONFIRMED, '1'],
        ];
        foreach ($rows as [$path, $value]) {
            $this->connection->insert('core_config_data', [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => $path,
                'value' => $value,
            ]);
        }
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
     * @return int[]
     */
    private function subscriberIds(): array
    {
        return array_map('intval', $this->connection->fetchCol(
            $this->connection->select()->from('newsletter_subscriber', ['subscriber_id'])
        ));
    }
}
