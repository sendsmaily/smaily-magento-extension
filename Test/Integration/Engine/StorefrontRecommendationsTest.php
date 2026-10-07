<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Catalog\Model\Product;
use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\View\Element\AbstractBlock;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Result\Layout;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Controller\Recommendations\Index;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\RecommendedProducts;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Engine\StorefrontRecommendations;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\OrderPlacer;
use Smaily\Connect\Model\Privacy\ProfilingConsent;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * PRO-3790: the storefront recommendations route end to end — the real
 * sending gate on stored connection settings, the real identity decision,
 * engine client and cache — against a stubbed engine transport. The
 * catalog lookup is stubbed (no catalog in this harness); it hands each
 * slot back as one card. Values are synthetic.
 */
class StorefrontRecommendationsTest extends IntegrationTestCase
{
    private const ENDPOINT = 'https://intelligence.smaily.com/api/v1/recommendations/customer';
    private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    private const ANSWER = '{"slots":[{"position":1,"rec_id":"' . self::REC_ID . '","sku":"MJ07",'
        . '"external_id":"7","name":"Jacket","price":29.99,"in_stock":true}]}';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var array<string, string> */
    private array $cacheStore = [];

    /** @var array<string, string> */
    private array $cookies = [];

    private bool $loggedIn = false;

    private bool $profilingAllowed = true;

    private bool $cookieNoticeHoldsBack = false;

    /** @var array{code: int|null, items: mixed} */
    private array $answer = ['code' => null, 'items' => null];

    protected function setUp(): void
    {
        parent::setUp();
        $scopeConfig = $this->env->getScopeConfig();
        $scopeConfig->setValue(Settings::XML_PATH_CONNECTED, '1');
        $scopeConfig->setValue(
            Settings::XML_PATH_API_KEY,
            $this->objectManager->get(EncryptorInterface::class)->encrypt('sk_test_key')
        );
        $scopeConfig->setValue(Settings::XML_PATH_TENANT_ID, 'tenant-1');
        $scopeConfig->setValue(
            Settings::XML_PATH_ENDPOINTS,
            (string)json_encode(['recommendations_customer' => self::ENDPOINT])
        );
    }

    public function testALoggedInShopperIsAskedAboutByTheCustomerIdAndShownTheCards(): void
    {
        $this->loggedIn = true;
        $this->cookies = ['smaily_rec_uid' => 'vt_8f3k2a'];

        $this->controller([new Response(200, [], self::ANSWER)])->execute();

        self::assertSame(['customer_external_id' => '42', 'limit' => 4], $this->sentBody(0));
        self::assertSame(self::ENDPOINT, (string)$this->history[0]['request']->getUri());
        self::assertSame('Bearer sk_test_key', $this->history[0]['request']->getHeaderLine('Authorization'));
        self::assertSame([['rec_id' => self::REC_ID, 'external_id' => '7', 'sku' => 'MJ07']], $this->answer['items']);
    }

    public function testAGuestWithAVisitorTokenIsAskedAboutByTheToken(): void
    {
        $this->cookies = ['smaily_rec_uid' => 'vt_8f3k2a'];

        $this->controller([new Response(200, [], self::ANSWER)])->execute();

        self::assertSame(['smaily_visitor_token' => 'vt_8f3k2a', 'limit' => 4], $this->sentBody(0));
    }

    public function testAGuestWithoutConsentIsNotAskedAbout(): void
    {
        $this->cookies = ['smaily_rec_uid' => 'vt_8f3k2a'];
        $this->cookieNoticeHoldsBack = true;

        $this->controller([new Response(200, [], self::ANSWER)])->execute();

        self::assertSame([], $this->history);
        self::assertSame(200, $this->answer['code']);
    }

    public function testAShopperWhoObjectedToProfilingIsNotAskedAboutNotEvenByTheToken(): void
    {
        $this->loggedIn = true;
        $this->profilingAllowed = false;
        $this->cookies = ['smaily_rec_uid' => 'vt_8f3k2a'];

        $this->controller([new Response(200, [], self::ANSWER)])->execute();

        self::assertSame([], $this->history);
        self::assertSame(200, $this->answer['code']);
    }

    public function testARefusedAccountIsNotAskedAndTheRouteAnswers404(): void
    {
        $this->env->getScopeConfig()->setValue(Settings::XML_PATH_REFUSED_AT, '2026-10-08 10:00:00');
        $this->cookies = ['smaily_rec_uid' => 'vt_8f3k2a'];

        $this->controller([new Response(200, [], self::ANSWER)])->execute();

        self::assertSame([], $this->history);
        self::assertSame(404, $this->answer['code']);
    }

    public function testASecondPageViewIsAnsweredFromTheCache(): void
    {
        $this->cookies = ['smaily_rec_uid' => 'vt_8f3k2a'];

        $this->controller([new Response(200, [], self::ANSWER)])->execute();
        $this->answer = ['code' => null, 'items' => null];
        $this->controller([new Response(200, [], self::ANSWER)])->execute();

        self::assertCount(0, $this->history, 'the second controller made no engine call');
        self::assertSame([['rec_id' => self::REC_ID, 'external_id' => '7', 'sku' => 'MJ07']], $this->answer['items']);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(int $index): array
    {
        self::assertArrayHasKey($index, $this->history);

        return (array)json_decode((string)$this->history[$index]['request']->getBody(), true);
    }

    /**
     * @param Response[] $engineResponses
     */
    private function controller(array $engineResponses): Index
    {
        $this->history = [];
        $settings = $this->objectManager->create(Settings::class, [
            'cacheTypeList' => $this->createMock(TypeListInterface::class),
        ]);

        return new Index(
            $this->request(),
            $this->resultFactory(),
            $settings,
            $this->recommendations($settings, $engineResponses),
            $this->cardsPerSlot(),
            new FixedWindowCounter($this->cache()),
            $this->clock,
            $this->createMock(RemoteAddress::class)
        );
    }

    /**
     * @param Response[] $engineResponses
     */
    private function recommendations(Settings $settings, array $engineResponses): StorefrontRecommendations
    {
        $handlerStack = HandlerStack::create(new MockHandler($engineResponses));
        $handlerStack->push(Middleware::history($this->history));
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack): HttpClient {
                $config['handler'] = $handlerStack;

                return new HttpClient($config);
            }
        );
        $client = $this->objectManager->create(Client::class, [
            'settings' => $settings,
            'httpClientFactory' => $httpClientFactory,
            'productMetadata' => $this->createMock(ProductMetadataInterface::class),
            'storeManager' => $this->createMock(StoreManagerInterface::class),
            'logger' => $this->createMock(Logger::class),
            'sleeper' => $this->createMock(SleeperInterface::class),
            'storefrontScript' => $this->createMock(StorefrontScript::class),
        ]);

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('getCookie')->willReturnCallback(fn (string $name) => $this->cookies[$name] ?? null);
        $attribution = $this->objectManager->create(AttributionManager::class, [
            'settings' => $settings,
            'cookieManager' => $cookieManager,
            'orderPlacer' => $this->objectManager->create(OrderPlacer::class, [
                'moduleManager' => $this->createMock(ModuleManager::class),
            ]),
        ]);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('shopper@example.com');
        $session = $this->createMock(CustomerSession::class);
        $session->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->loggedIn);
        $session->method('getCustomerId')->willReturn(42);
        $session->method('getCustomerData')->willReturn($customer);

        $profiling = $this->createMock(ProfilingConsent::class);
        $profiling->method('isAllowed')->willReturnCallback(fn (): bool => $this->profilingAllowed);

        $cookieHelper = $this->createMock(CookieHelper::class);
        $cookieHelper->method('isUserNotAllowSaveCookie')->willReturnCallback(
            fn (): bool => $this->cookieNoticeHoldsBack
        );

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new StorefrontRecommendations(
            $settings,
            $client,
            $profiling,
            $attribution,
            $session,
            $cookieHelper,
            $storeManager,
            $this->cache(),
            $this->createMock(Logger::class)
        );
    }

    /**
     * The catalog lookup: one card per slot, the slot itself standing in
     * for the product's data.
     */
    private function cardsPerSlot(): RecommendedProducts
    {
        $products = $this->createMock(RecommendedProducts::class);
        $products->method('forSlots')->willReturnCallback(fn (array $slots): array => array_map(
            fn (array $slot): array => [
                'product' => $this->createMock(Product::class),
                'url' => 'https://shop.example/p.html',
                'slot' => $slot,
            ],
            $slots
        ));

        return $products;
    }

    private function request(): HttpRequest
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getHeader')->willReturn('same-origin');

        return $request;
    }

    /**
     * The application cache, in memory and shared by every controller a
     * test builds.
     */
    private function cache(): CacheInterface
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cacheStore[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $value, string $key): bool {
            $this->cacheStore[$key] = $value;

            return true;
        });

        return $cache;
    }

    private function resultFactory(): ResultFactory
    {
        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($raw) {
            $this->answer['code'] = $code;

            return $raw;
        });

        $block = $this->createMock(AbstractBlock::class);
        $block->method('setData')->willReturnCallback(function (string $key, array $items) use ($block) {
            $this->answer['code'] = 200;
            $this->answer['items'] = array_column($items, 'slot');

            return $block;
        });
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($block);
        $layoutResult = $this->createMock(Layout::class);
        $layoutResult->method('getLayout')->willReturn($layout);
        $layoutResult->method('setHeader')->willReturnSelf();

        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturnCallback(
            fn (string $type) => $type === ResultFactory::TYPE_LAYOUT ? $layoutResult : $raw
        );

        return $resultFactory;
    }
}
