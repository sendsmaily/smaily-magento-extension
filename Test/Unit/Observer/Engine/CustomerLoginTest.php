<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\Observer;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\MarketingConsent;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\OrderPlacer;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Observer\Engine\CustomerLogin;

/**
 * Identity merge on login. Magento's own login (Customer\Model\Session)
 * dispatches customer_login with the customer model; a customer token login
 * (Integration\Model\CustomerTokenService — GraphQL generateCustomerToken,
 * REST integration/customer/token) with the customer data object (PRO-3917).
 * An admin's "Login as Customer" (loginascustomer/login/index) dispatches the
 * same event from the admin's browser and links nothing (PRO-3920).
 * The cookies are read by the real AttributionManager: a store-created
 * `vs_` visitor token goes with the merge only with the shopper's marketing
 * consent, an engine `vt_` token as before (CON-181).
 */
class CustomerLoginTest extends TestCase
{
    private const SESSION_ID = '0b6f3c1e-2a4d-4c8e-9f10-5d2b7a9e4c31';
    private const VISITOR_TOKEN = 'vt_abc123';
    private const STORE_VISITOR_TOKEN = 'vs_4fK9a2LmQ7xZ0bR3tY8wP1';

    private EventQueue&MockObject $eventQueue;
    private bool $engineConnected = true;
    private ?string $route = 'customer';
    private bool $marketingConsent = false;

    /** @var array{rec_id: ?string, visitor_token: ?string, rec_ctx: ?string, anon_session_id: ?string} */
    private array $cookies = [
        'rec_id' => null,
        'visitor_token' => self::VISITOR_TOKEN,
        'rec_ctx' => null,
        'anon_session_id' => self::SESSION_ID,
    ];

    protected function setUp(): void
    {
        $this->eventQueue = $this->createMock(EventQueue::class);
    }

    public function testALoginOnMagentosOwnPagesQueuesTheMerge(): void
    {
        $this->expectMerge();

        $this->observer()->execute($this->eventFor($this->customerModel('Person@Example.com ')));
    }

    public function testATokenLoginQueuesTheSameMerge(): void
    {
        $this->expectMerge();

        $this->observer()->execute($this->eventFor($this->customerDataObject('Person@Example.com ')));
    }

    public function testATokenLoginWithOnlyTheSessionCookieQueuesTheMerge(): void
    {
        $this->cookies['visitor_token'] = null;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static fn (array $payload): bool => $payload['anon_session_id'] === self::SESSION_ID
                && !array_key_exists('smaily_visitor_token', $payload)),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));
    }

    public function testAStoreCreatedTokenWithoutConsentIsLeftOffTheMerge(): void
    {
        $this->cookies['visitor_token'] = self::STORE_VISITOR_TOKEN;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static fn (array $payload): bool => $payload['anon_session_id'] === self::SESSION_ID
                && !array_key_exists('smaily_visitor_token', $payload)),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    public function testAStoreCreatedTokenWithConsentGoesWithTheMerge(): void
    {
        $this->cookies['visitor_token'] = self::STORE_VISITOR_TOKEN;
        $this->marketingConsent = true;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(
                static fn (array $payload): bool => $payload['smaily_visitor_token'] === self::STORE_VISITOR_TOKEN
            ),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    public function testAStoreCreatedTokenAloneWithoutConsentQueuesNothing(): void
    {
        $this->cookies['visitor_token'] = self::STORE_VISITOR_TOKEN;
        $this->cookies['anon_session_id'] = null;
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));
    }

    public function testATokenLoginWithoutTheCookiesQueuesNothing(): void
    {
        $this->cookies['visitor_token'] = null;
        $this->cookies['anon_session_id'] = null;
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));
    }

    public function testAnApiLoginWithoutARouteQueuesTheMerge(): void
    {
        $this->route = null;
        $this->expectMerge();

        $this->observer()->execute($this->eventFor($this->customerDataObject('Person@Example.com ')));
    }

    public function testAnAdminsLoginAsCustomerQueuesNothing(): void
    {
        $this->route = 'loginascustomer';
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    public function testNothingIsQueuedWithoutCampaignIntelligence(): void
    {
        $this->engineConnected = false;
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));
    }

    public function testACustomerWithoutAnEmailQueuesNothing(): void
    {
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerDataObject(null)));
    }

    public function testAnEventWithoutACustomerQueuesNothing(): void
    {
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor(null));
    }

    private function expectMerge(): void
    {
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static function (array $payload): bool {
                self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $payload['merge_ts']);
                unset($payload['merge_ts']);
                self::assertSame([
                    'customer_email' => 'person@example.com',
                    'customer_external_id' => '42',
                    'merge_reason' => 'login',
                    'store_id' => 3,
                    'anon_session_id' => self::SESSION_ID,
                    'smaily_visitor_token' => self::VISITOR_TOKEN,
                ], $payload);

                return true;
            }),
            '42'
        );
    }

    private function observer(): CustomerLogin
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getModuleName')->willReturn($this->route);
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($this->engineConnected);
        $settings->method('getEngineConfig')->willReturn([]);
        $byName = [
            AttributionManager::DEFAULT_COOKIE_VISITOR => $this->cookies['visitor_token'],
            AttributionManager::DEFAULT_COOKIE_SESSION => $this->cookies['anon_session_id'],
        ];
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(static fn (string $name) => $byName[$name] ?? null);
        $consent = $this->createMock(MarketingConsent::class);
        $consent->method('isGiven')->willReturn($this->marketingConsent);
        $attributionManager = new AttributionManager(
            $settings,
            $cookieManager,
            $this->createMock(ResourceConnection::class),
            $this->createMock(OrderPlacer::class),
            $consent
        );

        return new CustomerLogin($request, $settings, $attributionManager, $this->eventQueue);
    }

    private function customerModel(?string $email): Customer
    {
        // getEmail and getStoreId are magic getters on the model (addMethods).
        $customer = $this->getMockBuilder(Customer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->addMethods(['getEmail', 'getStoreId'])
            ->getMock();
        $customer->method('getEmail')->willReturn($email);
        $customer->method('getId')->willReturn('42');
        $customer->method('getStoreId')->willReturn('3');

        return $customer;
    }

    private function customerDataObject(?string $email): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn($email);
        $customer->method('getId')->willReturn(42);
        $customer->method('getStoreId')->willReturn(3);

        return $customer;
    }

    private function eventFor(Customer|CustomerInterface|null $customer): Observer
    {
        return new Observer(['event' => new Event(['customer' => $customer])]);
    }
}
