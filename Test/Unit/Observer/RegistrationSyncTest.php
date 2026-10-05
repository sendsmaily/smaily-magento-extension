<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Privacy\ProfilingOptOuts;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Observer\CustomerSaveAfter;
use Smaily\Connect\Observer\SubscriberSaveAfter;

/**
 * PRO-3628: a storefront registration with the newsletter box ticked runs
 * Magento's customer save twice — the account, then the password-reset
 * token — and the newsletter subscription between them. The registration
 * queues one contact sync for it, not one per save.
 */
class RegistrationSyncTest extends TestCase
{
    private const EMAIL = 'person@example.com';

    /** @var array<int, array{event_type: string, payload: array<string, mixed>}> */
    private array $queued = [];

    private int $subscriberStatus = 0;

    private string $firstName = 'Test';

    private SyncDispatcher $dispatcher;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Support/Stub/SubscriberFactory.php';
    }

    public function testARegistrationWithTheNewsletterBoxQueuesOneContactSyncInConsentMode(): void
    {
        $this->register(SyncMode::MODE_CONSENT);

        self::assertSame([
            ['email' => self::EMAIL, 'is_unsubscribed' => 0, 'first_name' => 'Test'],
        ], $this->contactSyncs());
        self::assertCount(1, $this->rowsOf(EventType::AUTOMATION_TRIGGER), 'PRO-3580: one welcome');
    }

    /**
     * All customers: the account syncs before the subscription exists, the
     * subscription adds its status, and the token save adds nothing.
     */
    public function testTheTokenSaveAddsNoContactSyncInAllCustomersMode(): void
    {
        $this->register(SyncMode::MODE_LEGITIMATE_INTEREST);

        self::assertSame([
            ['email' => self::EMAIL, 'first_name' => 'Test'],
            ['email' => self::EMAIL, 'is_unsubscribed' => 0, 'first_name' => 'Test'],
        ], $this->contactSyncs());
    }

    public function testAProfileEditAfterTheRegistrationStillSyncs(): void
    {
        $this->register(SyncMode::MODE_CONSENT);

        $this->firstName = 'Changed';
        $this->customerSaved(SyncMode::MODE_CONSENT);

        self::assertSame([
            ['email' => self::EMAIL, 'is_unsubscribed' => 0, 'first_name' => 'Test'],
            ['email' => self::EMAIL, 'is_unsubscribed' => 0, 'first_name' => 'Changed'],
        ], $this->contactSyncs());
    }

    /**
     * The order of Magento's createAccount(): the account save, then the
     * newsletter plugin's subscription, then the reset-token save.
     */
    private function register(string $mode): void
    {
        $this->dispatcher = $this->createDispatcher();

        $this->customerSaved($mode);
        $this->subscriberStatus = Subscriber::STATUS_SUBSCRIBED;
        $this->subscriberSaved($mode);
        $this->customerSaved($mode);
    }

    private function customerSaved(string $mode): void
    {
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('loadByCustomer')->willReturnSelf();
        $subscriber->method('getStatus')->willReturn($this->subscriberStatus);
        $subscriberFactory = $this->createMock(SubscriberFactory::class);
        $subscriberFactory->method('create')->willReturn($subscriber);
        $config = $this->config($mode);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('customer_data_object')->willReturn($this->customer());
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        (new CustomerSaveAfter($config, new Mode($config), new ReconcileGuard(), $this->dispatcher, $subscriberFactory))
            ->execute($observer);
    }

    private function subscriberSaved(string $mode): void
    {
        $config = $this->config($mode);
        $state = $this->createMock(State::class);
        $state->method('getAreaCode')->willReturn('frontend');
        $customerRepository = $this->createMock(CustomerRepositoryInterface::class);
        $customerRepository->method('getById')->willReturn($this->customer());

        $subscriber = $this->getMockBuilder(Subscriber::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isStatusChanged', 'getStatus', 'getEmail'])
            ->getMock();
        $subscriber->method('isStatusChanged')->willReturn(true);
        $subscriber->method('getStatus')->willReturn($this->subscriberStatus);
        $subscriber->method('getEmail')->willReturn(self::EMAIL);
        $subscriber->setData('store_id', 1);
        $subscriber->setData('customer_id', 42);

        $event = $this->createMock(Event::class);
        $event->method('getData')->with('subscriber')->willReturn($subscriber);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        (new SubscriberSaveAfter(
            $config,
            new Mode($config),
            new ReconcileGuard(),
            $this->dispatcher,
            $this->payloadBuilder(),
            $customerRepository,
            $state,
            new StorefrontSubscription()
        ))->execute($observer);
    }

    private function createDispatcher(): SyncDispatcher
    {
        $store = $this->createMock(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $queue = $this->createMock(EventQueue::class);
        $queue->method('enqueue')->willReturnCallback(
            function (string $eventType, array $payload): bool {
                $this->queued[] = ['event_type' => $eventType, 'payload' => $payload];

                return true;
            }
        );

        return new SyncDispatcher(
            $this->payloadBuilder(),
            $this->createMock(LanguageResolver::class),
            $storeManager,
            $queue,
            new ContactEntity($this->createMock(ProfilingOptOuts::class))
        );
    }

    /**
     * Builds the payload from what it is given, as the real builder does.
     */
    private function payloadBuilder(): SubscriberPayloadBuilder
    {
        $builder = $this->createMock(SubscriberPayloadBuilder::class);
        $builder->method('build')->willReturnCallback(
            static function (string $email, int $storeId, ?bool $isUnsubscribed, ?CustomerInterface $customer): array {
                $payload = ['email' => $email];
                if ($isUnsubscribed !== null) {
                    $payload['is_unsubscribed'] = $isUnsubscribed ? 1 : 0;
                }
                if ($customer !== null) {
                    $payload['first_name'] = (string)$customer->getFirstname();
                }

                return $payload;
            }
        );

        return $builder;
    }

    private function config(string $mode): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('isSyncEnabled')->willReturn(true);
        $config->method('isConnected')->willReturn(true);
        $config->method('isWelcomeEnabled')->willReturn(true);
        $config->method('getSyncMode')->willReturn($mode);

        return $config;
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getStoreId')->willReturn(1);
        $customer->method('getWebsiteId')->willReturn(1);
        $customer->method('getEmail')->willReturn(self::EMAIL);
        $customer->method('getFirstname')->willReturn($this->firstName);

        return $customer;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contactSyncs(): array
    {
        return array_map(
            static fn (array $row): array => $row['payload']['contact'],
            $this->rowsOf(EventType::CONTACT_SYNC)
        );
    }

    /**
     * @return array<int, array{event_type: string, payload: array<string, mixed>}>
     */
    private function rowsOf(string $eventType): array
    {
        return array_values(array_filter(
            $this->queued,
            static fn (array $row): bool => $row['event_type'] === $eventType
        ));
    }
}
