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
use Magento\Framework\Phrase;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\FailureToSendException;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\AttributionCookieWriter;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\AttributionShape;
use Smaily\Connect\Model\Engine\MarketingConsent;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\StoreVisitorToken;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\OrderPlacer;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;
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
 *
 * CON-260 (contract §7 "Store-created token at login"): a shopper with
 * marketing consent and no visitor token gets a `vs_` token in the
 * visitor-token cookie, set by the server, and the merge carries it; a
 * token the browser holds is kept; without consent nothing is created.
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
    private bool $storefrontUrl = false;
    private bool $cookieFails = false;

    /** @var array<string, array{value: string, metadata: array<string, mixed>}> */
    private array $set = [];

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

    public function testAConsentingShopperWithoutATokenGetsOneInTheCookieAndTheMerge(): void
    {
        $this->marketingConsent = true;
        $this->cookies['visitor_token'] = null;
        $sent = null;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static function (array $payload) use (&$sent): bool {
                $sent = $payload;

                return true;
            }),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));

        self::assertSame([AttributionManager::DEFAULT_COOKIE_VISITOR], array_keys($this->set));
        $token = $this->set[AttributionManager::DEFAULT_COOKIE_VISITOR]['value'];
        self::assertTrue(AttributionShape::isStoreVisitorToken($token), $token);
        $expected = [
            PublicCookieMetadata::KEY_DOMAIN => '.store.example',
            PublicCookieMetadata::KEY_PATH => '/',
            PublicCookieMetadata::KEY_SECURE => true,
            PublicCookieMetadata::KEY_HTTP_ONLY => false,
            PublicCookieMetadata::KEY_SAME_SITE => 'Lax',
            PublicCookieMetadata::KEY_DURATION => 365 * 86400,
        ];
        ksort($expected);
        self::assertSame(
            $expected,
            $this->set[AttributionManager::DEFAULT_COOKIE_VISITOR]['metadata'],
            'The attributes and lifetime of the server-set click cookies (CON-211)'
        );
        self::assertIsArray($sent);
        self::assertSame($token, $sent['smaily_visitor_token']);
        self::assertSame(self::SESSION_ID, $sent['anon_session_id']);
        self::assertSame('user_logged_in', $sent['merge_reason']);
    }

    public function testAConsentingShopperWithoutAnyCookieIsMergedByTheNewTokenAlone(): void
    {
        $this->marketingConsent = true;
        $this->cookies['visitor_token'] = null;
        $this->cookies['anon_session_id'] = null;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(fn (array $payload): bool => !array_key_exists('anon_session_id', $payload)
                && $payload['smaily_visitor_token']
                    === $this->set[AttributionManager::DEFAULT_COOKIE_VISITOR]['value']),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    /**
     * A token login (GraphQL, REST) is covered as the merge covers it: the
     * cookie goes on the token request's answer.
     */
    public function testAConsentingShopperGetsATokenAtATokenLoginToo(): void
    {
        $this->marketingConsent = true;
        $this->cookies['visitor_token'] = null;
        $this->eventQueue->expects(self::once())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));

        self::assertArrayHasKey(AttributionManager::DEFAULT_COOKIE_VISITOR, $this->set);
    }

    /**
     * @dataProvider heldTokenProvider
     */
    public function testATokenTheBrowserHoldsIsKeptAndSentNeverReplaced(string $held): void
    {
        $this->marketingConsent = true;
        $this->cookies['visitor_token'] = $held;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static fn (array $payload): bool => $payload['smaily_visitor_token'] === $held),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));

        self::assertSame([], $this->set, 'No new cookie');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function heldTokenProvider(): array
    {
        return [
            'an engine token from an email link' => [self::VISITOR_TOKEN],
            'a store-created token from an earlier login' => [self::STORE_VISITOR_TOKEN],
        ];
    }

    public function testWithoutConsentNoTokenIsCreatedSentOrSet(): void
    {
        $this->cookies['visitor_token'] = null;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static fn (array $payload): bool => $payload['anon_session_id'] === self::SESSION_ID
                && !array_key_exists('smaily_visitor_token', $payload)),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));

        self::assertSame([], $this->set);
    }

    public function testWithoutConsentOrCookiesNothingIsCreatedOrQueued(): void
    {
        $this->cookies['visitor_token'] = null;
        $this->cookies['anon_session_id'] = null;
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));

        self::assertSame([], $this->set);
    }

    /**
     * Without consent a store-created token in the cookie is not sent, and
     * it is not replaced either.
     */
    public function testWithoutConsentAHeldStoreTokenIsLeftAsItIs(): void
    {
        $this->cookies['visitor_token'] = self::STORE_VISITOR_TOKEN;
        $this->eventQueue->expects(self::once())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));

        self::assertSame([], $this->set);
    }

    /**
     * PRO-3918: a separate storefront owns its shoppers' cookies, and its
     * own consent banner decides.
     */
    public function testAStoreViewWithASeparateStorefrontCreatesNoToken(): void
    {
        $this->marketingConsent = true;
        $this->storefrontUrl = true;
        $this->cookies['visitor_token'] = null;
        $this->cookies['anon_session_id'] = null;
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));

        self::assertSame([], $this->set);
    }

    public function testATokenWhoseCookieCannotBeSetIsNotSent(): void
    {
        $this->marketingConsent = true;
        $this->cookieFails = true;
        $this->cookies['visitor_token'] = null;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static fn (array $payload): bool => $payload['anon_session_id'] === self::SESSION_ID
                && !array_key_exists('smaily_visitor_token', $payload)),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    public function testAnAdminsLoginAsCustomerCreatesNoToken(): void
    {
        $this->marketingConsent = true;
        $this->route = 'loginascustomer';
        $this->cookies['visitor_token'] = null;
        $this->eventQueue->expects(self::never())->method('enqueue');

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));

        self::assertSame([], $this->set);
    }

    /**
     * CON-262 (contract v1.23.0 §4 group c): a consenting login puts the
     * customer in scope, so the merge row asks the handler to send the
     * customer first — with a token the browser holds too.
     */
    public function testAConsentingLoginAsksForTheCustomerFirst(): void
    {
        $this->marketingConsent = true;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(static fn (array $payload): bool => $payload[IdentityMergeHandler::SEND_CUSTOMER] === true
                && $payload['smaily_visitor_token'] === self::VISITOR_TOKEN),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    public function testALoginWithoutConsentDoesNotAskForTheCustomer(): void
    {
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(
                static fn (array $payload): bool => !array_key_exists(IdentityMergeHandler::SEND_CUSTOMER, $payload)
            ),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerModel('person@example.com')));
    }

    /**
     * PRO-3918: at a store view with a separate storefront, its own banner
     * decides, so the store's consent check does not send the customer.
     */
    public function testAStoreViewWithASeparateStorefrontDoesNotAskForTheCustomer(): void
    {
        $this->marketingConsent = true;
        $this->storefrontUrl = true;
        $this->eventQueue->expects(self::once())->method('enqueue')->with(
            EventType::ENGINE_IDENTITY_MERGE,
            self::callback(
                static fn (array $payload): bool => !array_key_exists(IdentityMergeHandler::SEND_CUSTOMER, $payload)
            ),
            '42'
        );

        $this->observer()->execute($this->eventFor($this->customerDataObject('person@example.com')));
    }

    public function testTheLoginIsNamedAsTheEngineAcceptsIt(): void
    {
        self::assertSame('user_logged_in', IdentityMergeHandler::MERGE_REASON_LOGIN);
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
                    'merge_reason' => 'user_logged_in',
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
        $request->method('isSecure')->willReturn(true);
        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($this->engineConnected);
        $settings->method('getEngineConfig')->willReturn([]);
        $byName = [
            AttributionManager::DEFAULT_COOKIE_VISITOR => $this->cookies['visitor_token'],
            AttributionManager::DEFAULT_COOKIE_SESSION => $this->cookies['anon_session_id'],
        ];
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(static fn (string $name) => $byName[$name] ?? null);
        $cookieManager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $metadata): void {
                if ($this->cookieFails) {
                    throw new FailureToSendException(new Phrase('headers already sent'));
                }
                $array = $metadata->__toArray();
                ksort($array);
                $this->set[$name] = ['value' => $value, 'metadata' => $array];
            }
        );
        $consent = $this->createMock(MarketingConsent::class);
        $consent->method('isGiven')->willReturn($this->marketingConsent);
        $attributionManager = new AttributionManager(
            $settings,
            $cookieManager,
            $this->createMock(ResourceConnection::class),
            $this->createMock(OrderPlacer::class),
            $consent
        );
        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')
            ->willReturnCallback(static fn (): PublicCookieMetadata => new PublicCookieMetadata());
        $sessionConfig = $this->createMock(SessionConfig::class);
        $sessionConfig->method('getCookieDomain')->willReturn('.store.example');
        $sessionConfig->method('getCookiePath')->willReturn('/');
        $config = $this->createMock(Config::class);
        $config->method('hasStorefrontUrl')->willReturn($this->storefrontUrl);

        return new CustomerLogin(
            $request,
            $settings,
            $attributionManager,
            $this->eventQueue,
            $consent,
            new StoreVisitorToken(
                $attributionManager,
                new AttributionCookieWriter($request, $cookieManager, $metadataFactory, $sessionConfig),
                $this->createMock(Logger::class)
            ),
            $config
        );
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
