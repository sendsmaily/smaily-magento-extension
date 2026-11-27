<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Event;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Newsletter\Helper\Data as NewsletterHelper;
use Magento\Newsletter\Model\CustomerSubscriberCache;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Newsletter\Model\SubscriptionManager;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\CustomerIngest;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Observer\Engine\SubscriberJoined;
use Smaily\Connect\Observer\Engine\SubscriberResubscribed;
use Smaily\Connect\Observer\Engine\SubscriberUnsubscribed;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-436: a newsletter join, rejoin or leave reaches Campaign Intelligence
 * once — read from the status saved against the status loaded, not from the
 * subscriber's status-changed flag, which code that sets the status field
 * directly leaves unset and setStatus() sets for an unchanged status loaded
 * as a string. A join offers the customer to the live customer sync
 * (SubscriberJoined); a rejoin and a leave queue the profiling opt-in and
 * opt-out for the engine (SubscriberResubscribed, SubscriberUnsubscribed).
 *
 * On real newsletter_subscriber rows, the real marketing event queue and
 * ProfilingConsent, Magento's own SubscriptionManager and subscriber model;
 * the customer sync and the store's opt-out record are stubbed.
 */
class SubscriberEngineEventsTest extends IntegrationTestCase
{
    use EngineConnectionsStub;

    private SchemaInstaller $schema;

    private StoreManagerInterface $storeManager;

    private bool $engineConnected = true;

    /** @var array<string, bool> the store's opt-outs: address => by unsubscribing */
    private array $optOutRecords = [];

    /** @var list<int> */
    private array $joined = [];

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
     * A customer's pending subscription confirmed through Magento's
     * confirmation link joins once.
     */
    public function testAConfirmedSubscriptionJoinsOnce(): void
    {
        $id = $this->seedSubscriber('pending@example.com', Subscriber::STATUS_NOT_ACTIVE, 7, 'joincode');
        $subscriber = $this->loadSubscriber($id);

        self::assertTrue($subscriber->confirm('joincode'));

        self::assertSame([7], $this->joined);
        self::assertSame([], $this->queuedOptOuts());
    }

    /**
     * A guest who left signs up again through the storefront newsletter
     * form, and a subscribed guest leaves through the unsubscribe link: one
     * opt-in and one opt-out reach the engine queue.
     */
    public function testANewsletterFormRejoinAndAnUnsubscribeLinkLeaveAreSentOnceEach(): void
    {
        $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED);
        $this->optOutRecords['returning@example.com'] = true;
        $this->seedSubscriber('leaving@example.com', Subscriber::STATUS_SUBSCRIBED, 0, 'leavecode');

        $this->subscriptionManager()->subscribe('returning@example.com', 1);
        $this->subscriptionManager()->unsubscribe('leaving@example.com', 1, 'leavecode');

        self::assertSame([
            ['returning@example.com', false],
            ['leaving@example.com', true],
        ], $this->queuedOptOuts());
    }

    /**
     * A join written on the status field directly, without the flag.
     */
    public function testAJoinWithoutTheChangedFlagIsSent(): void
    {
        $id = $this->seedSubscriber('pending@example.com', Subscriber::STATUS_NOT_ACTIVE, 7);
        $subscriber = $this->loadSubscriber($id);

        $this->save($subscriber->setSubscriberStatus(Subscriber::STATUS_SUBSCRIBED));

        self::assertFalse($subscriber->isStatusChanged());
        self::assertSame([7], $this->joined);
    }

    /**
     * A rejoin written on the status field directly, without the flag.
     */
    public function testARejoinWithoutTheChangedFlagIsSent(): void
    {
        $id = $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED, 7);
        $this->optOutRecords['returning@example.com'] = true;
        $subscriber = $this->loadSubscriber($id);

        $this->save($subscriber->setSubscriberStatus(Subscriber::STATUS_SUBSCRIBED));

        self::assertFalse($subscriber->isStatusChanged());
        self::assertSame([['returning@example.com', false]], $this->queuedOptOuts());
        self::assertSame([], $this->joined);
    }

    /**
     * A leave written as Magento's Newsletter Problem Reports "Unsubscribe"
     * writes it: the status field set directly, the magic is_status_changed
     * set instead of the flag.
     */
    public function testALeaveAsTheProblemReportsUnsubscribeWritesItIsSent(): void
    {
        $id = $this->seedSubscriber('leaving@example.com', Subscriber::STATUS_SUBSCRIBED);
        $subscriber = $this->loadSubscriber($id);

        $this->save($subscriber->setSubscriberStatus(Subscriber::STATUS_UNSUBSCRIBED)->setIsStatusChanged(true));

        self::assertFalse($subscriber->isStatusChanged());
        self::assertSame([['leaving@example.com', true]], $this->queuedOptOuts());
    }

    /**
     * A save that keeps the status — setStatus() with the status loaded,
     * signing up again while subscribed, leaving again while unsubscribed —
     * sends nothing.
     */
    public function testASaveThatKeepsTheStatusSendsNothing(): void
    {
        $subscribedId = $this->seedSubscriber('subscribed@example.com', Subscriber::STATUS_SUBSCRIBED, 7);
        $leftId = $this->seedSubscriber('left@example.com', Subscriber::STATUS_UNSUBSCRIBED, 8, 'leftcode');
        $this->optOutRecords['left@example.com'] = true;

        $this->save($this->loadSubscriber($subscribedId)->setStatus(Subscriber::STATUS_SUBSCRIBED)->setStoreId(2));
        $this->save($this->loadSubscriber($leftId)->setStatus(Subscriber::STATUS_UNSUBSCRIBED)->setStoreId(2));
        $this->subscriptionManager()->subscribe('subscribed@example.com', 1);
        $this->subscriptionManager()->unsubscribe('left@example.com', 1, 'leftcode');

        self::assertSame([], $this->queuedOptOuts());
        self::assertSame([], $this->joined);
    }

    /**
     * Without Campaign Intelligence connected, a leave and a rejoin change
     * no profiling choice and queue nothing.
     */
    public function testNothingIsSentWithoutCampaignIntelligence(): void
    {
        $this->engineConnected = false;
        $leavingId = $this->seedSubscriber('leaving@example.com', Subscriber::STATUS_SUBSCRIBED);
        $returningId = $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED);
        $this->optOutRecords['returning@example.com'] = true;

        $this->save($this->loadSubscriber($leavingId)->setSubscriberStatus(Subscriber::STATUS_UNSUBSCRIBED));
        $this->save($this->loadSubscriber($returningId)->setSubscriberStatus(Subscriber::STATUS_SUBSCRIBED));

        self::assertSame([], $this->queuedOptOuts());
        self::assertSame(['returning@example.com' => true], $this->optOutRecords);
    }

    /**
     * PRO-3594: a profiling opt-out the shopper made on their own stays when
     * they rejoin, also when the rejoin is written without the flag.
     */
    public function testARejoinLeavesTheShoppersOwnOptOutInPlace(): void
    {
        $id = $this->seedSubscriber('returning@example.com', Subscriber::STATUS_UNSUBSCRIBED);
        $this->optOutRecords['returning@example.com'] = false;

        $this->save($this->loadSubscriber($id)->setSubscriberStatus(Subscriber::STATUS_SUBSCRIBED));

        self::assertSame([], $this->queuedOptOuts());
        self::assertSame(['returning@example.com' => false], $this->optOutRecords);
    }

    /**
     * An event manager that hands a subscriber save to the three engine
     * subscriber observers, as newsletter_subscriber_save_after does.
     */
    private function observingEventManager(): EventManagerInterface
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturnCallback(fn (): bool => $this->engineConnected);
        $consent = $this->profilingConsent($settings);
        $customerIngest = $this->createMock(CustomerIngest::class);
        $customerIngest->method('joined')->willReturnCallback(function (int $customerId): void {
            $this->joined[] = $customerId;
        });
        $observers = [
            new SubscriberJoined($customerIngest),
            new SubscriberUnsubscribed($this->oneEngineConnection($settings), $consent),
            new SubscriberResubscribed($this->oneEngineConnection($settings), $consent),
        ];
        $eventManager = $this->createMock(EventManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data = []) use ($observers): void {
                if ($name === 'newsletter_subscriber_save_after') {
                    foreach ($observers as $observer) {
                        $observer->execute(new Observer(['event' => new Event($data)] + $data));
                    }
                }
            }
        );

        return $eventManager;
    }

    /**
     * The real ProfilingConsent on the real queue; the store's opt-out
     * record is this test's array.
     */
    private function profilingConsent(Settings&MockObject $settings): ProfilingConsent
    {
        $optOuts = $this->createMock(ProfilingOptOuts::class);
        $optOuts->method('moment')->willReturnCallback(
            fn (string $email): ?int => isset($this->optOutRecords[$email]) ? 1 : null
        );
        $optOuts->method('isByUnsubscribe')->willReturnCallback(
            fn (string $email): bool => $this->optOutRecords[$email] ?? false
        );
        $optOuts->method('record')->willReturnCallback(
            function (string $email, int $moment, bool $byUnsubscribe = false): void {
                $this->optOutRecords[$email] = $byUnsubscribe;
            }
        );
        $optOuts->method('forget')->willReturnCallback(function (string $email): void {
            unset($this->optOutRecords[$email]);
        });
        $provider = $this->createMock(SmailyClientProvider::class);
        $provider->method('forStore')->willThrowException(new SmailyClientException('Smaily is down'));

        /** @var ProfilingConsent $consent */
        $consent = $this->objectManager->create(ProfilingConsent::class, [
            'smailyClientProvider' => $provider,
            'connections' => $this->oneEngineConnection($settings),
            'optOuts' => $optOuts,
        ]);

        return $consent;
    }

    /**
     * Magento's own SubscriptionManager — what the storefront newsletter
     * form and the unsubscribe link call — on real subscriber models whose
     * saves reach the observers.
     */
    private function subscriptionManager(): SubscriptionManager
    {
        $eventManager = $this->observingEventManager();
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
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'customerSubscriberCache' => $this->createMock(CustomerSubscriberCache::class),
        ]);
    }

    /**
     * A stored subscriber, loaded through Magento's subscriber model whose
     * saves reach the observers.
     */
    private function loadSubscriber(int $subscriberId): Subscriber
    {
        $resource = $this->subscriberResource();
        $subscriber = $this->newSubscriber($this->observingEventManager(), $resource);
        $resource->load($subscriber, $subscriberId);

        return $subscriber;
    }

    /**
     * A real subscriber model and resource on the test database.
     */
    private function newSubscriber(EventManagerInterface $eventManager, SubscriberResource $resource): Subscriber
    {
        return $this->objectManager->create(Subscriber::class, [
            'context' => $this->objectManager->create(ModelContext::class, ['eventDispatcher' => $eventManager]),
            'newsletterData' => $this->createMock(NewsletterHelper::class),
            'transportBuilder' => $this->createMock(TransportBuilder::class),
            'storeManager' => $this->storeManager,
            'customerSession' => $this->createMock(CustomerSession::class),
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'customerAccountManagement' => $this->createMock(AccountManagementInterface::class),
            'inlineTranslation' => $this->createMock(StateInterface::class),
            'resource' => $resource,
            'customerFactory' => $this->createMock(CustomerInterfaceFactory::class),
            'dataObjectHelper' => $this->createMock(DataObjectHelper::class),
            'subscriptionManager' => $this->createMock(SubscriptionManagerInterface::class),
        ]);
    }

    /**
     * Save through the subscriber resource, as Subscriber::save() does.
     */
    private function save(Subscriber $subscriber): void
    {
        $this->subscriberResource()->save($subscriber);
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
     * The queued profiling choices for the engine, in queue order: address
     * and whether it is an opt-out.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function queuedOptOuts(): array
    {
        $choices = [];
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            if ($row['event_type'] === EventType::ENGINE_PROFILING_CONSENT) {
                $payload = json_decode((string)$row['payload'], true);
                $choices[] = [$payload['email'], $payload['opt_out']];
            }
        }

        return $choices;
    }
}
