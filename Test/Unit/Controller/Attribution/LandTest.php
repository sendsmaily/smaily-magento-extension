<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Attribution;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Phrase;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\CookieSizeLimitReachedException;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Attribution\Land;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;
use Smaily\Connect\Model\RateLimit\PerAddressLimiter;

/**
 * CON-211: the store's server sets the campaign-click cookies, so Safari
 * keeps them for their lifetime — the capture script's rules, the store's
 * cookie scope, and an answer no cache keeps.
 */
class LandTest extends TestCase
{
    private const REC_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    private const ENGINE_TOKEN = 'vt_8f3k2a9xq4';
    private const STORE_TOKEN = 'vs_4fK9a2LmQ7xZ0bR3tY8wP1';

    /** An engine config with other names and lifetimes than the defaults. */
    private const CLIENT_CONFIG = [
        'cookieVisitor' => 'tenant_uid',
        'cookieRecId' => 'tenant_rec',
        'cookieContext' => 'tenant_ctx',
        'ttlVisitorDays' => 400,
        'ttlRecIdDays' => 20,
        'ttlContextDays' => 10,
    ];

    /** @var array{code: int, contents: string|null, headers: array<string, string>} */
    private array $answer = ['code' => 200, 'contents' => null, 'headers' => []];

    /** @var array<string, array{value: string, metadata: array<string, mixed>}> */
    private array $set = [];

    /** @var array<string, array<string, mixed>> */
    private array $deleted = [];

    /** @var array<string, string> */
    private array $cacheStore = [];

    public function testANotConnectedStoreAnswers404AndSetsNothing(): void
    {
        $this->controller(['visitor_token' => self::ENGINE_TOKEN], connected: false)->execute();

        self::assertSame(404, $this->answer['code']);
        self::assertSame([], $this->set);
    }

    public function testAClickSetsEachCookieWithTheStoresScopeAndTheEngineLifetime(): void
    {
        $this->controller([
            'rec_id' => self::REC_ID,
            'visitor_token' => self::ENGINE_TOKEN,
            'context' => 'cart_abandoned',
        ])->execute();

        self::assertSame(204, $this->answer['code']);
        self::assertSame('', $this->answer['contents']);
        self::assertSame('no-store, private', $this->answer['headers']['Cache-Control']);
        self::assertSame(['tenant_rec', 'tenant_ctx', 'tenant_uid'], array_keys($this->set));
        self::assertSame(self::REC_ID, $this->set['tenant_rec']['value']);
        self::assertSame('cart_abandoned', $this->set['tenant_ctx']['value']);
        self::assertSame(self::ENGINE_TOKEN, $this->set['tenant_uid']['value']);
        $expected = [
            PublicCookieMetadata::KEY_DOMAIN => '.store.example',
            PublicCookieMetadata::KEY_PATH => '/',
            PublicCookieMetadata::KEY_SECURE => true,
            PublicCookieMetadata::KEY_HTTP_ONLY => false,
            PublicCookieMetadata::KEY_SAME_SITE => 'Lax',
            PublicCookieMetadata::KEY_DURATION => 400 * 86400,
        ];
        ksort($expected);
        self::assertSame($expected, $this->set['tenant_uid']['metadata']);
        self::assertSame(20 * 86400, $this->set['tenant_rec']['metadata'][PublicCookieMetadata::KEY_DURATION]);
        self::assertSame(10 * 86400, $this->set['tenant_ctx']['metadata'][PublicCookieMetadata::KEY_DURATION]);
        self::assertSame([], $this->deleted);
    }

    public function testOverPlainHttpTheCookieIsNotSecure(): void
    {
        $this->controller(['visitor_token' => self::ENGINE_TOKEN], secure: false)->execute();

        self::assertSame(204, $this->answer['code']);
        self::assertFalse($this->set['tenant_uid']['metadata'][PublicCookieMetadata::KEY_SECURE]);
    }

    /**
     * The context cookie rule: a click with a rec id and no context deletes
     * the context cookie, in the scope it was set in.
     */
    public function testARecIdWithoutAContextDeletesTheContextCookie(): void
    {
        $this->controller(['rec_id' => self::REC_ID, 'context' => 'bad context!'])->execute();

        self::assertSame(204, $this->answer['code']);
        self::assertSame(['tenant_rec'], array_keys($this->set));
        self::assertSame(['tenant_ctx'], array_keys($this->deleted));
        self::assertSame('.store.example', $this->deleted['tenant_ctx'][PublicCookieMetadata::KEY_DOMAIN]);
        self::assertSame('/', $this->deleted['tenant_ctx'][PublicCookieMetadata::KEY_PATH]);
    }

    /**
     * @dataProvider nothingInShapeProvider
     * @param array<string, mixed> $post
     */
    public function testAClickWithNothingInShapeAnswers400AndSetsNothing(array $post): void
    {
        $this->controller($post)->execute();

        self::assertSame(400, $this->answer['code']);
        self::assertSame([], $this->set);
        self::assertSame([], $this->deleted);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function nothingInShapeProvider(): array
    {
        return [
            'nothing' => [[]],
            'a store-created vs_ token (CON-177)' => [['visitor_token' => self::STORE_TOKEN]],
            'a token of neither form' => [['visitor_token' => 'vs_4fK9a2LmQ7']],
            'a malformed rec id with a context' => [['rec_id' => 'rec_abc123', 'context' => 'welcome']],
            'a context without a rec id' => [['context' => 'welcome']],
            'values sent as arrays' => [['rec_id' => [self::REC_ID], 'visitor_token' => [self::ENGINE_TOKEN]]],
        ];
    }

    public function testAStoreCreatedTokenBesideARecIdIsIgnored(): void
    {
        $this->controller(['rec_id' => self::REC_ID, 'visitor_token' => self::STORE_TOKEN])->execute();

        self::assertSame(204, $this->answer['code']);
        self::assertArrayNotHasKey('tenant_uid', $this->set);
    }

    public function testTheLimitPerAddressHolds(): void
    {
        $controller = $this->controller(['visitor_token' => self::ENGINE_TOKEN]);
        for ($i = 0; $i < 30; $i++) {
            $controller->execute();
            self::assertSame(204, $this->answer['code'], 'request ' . ($i + 1) . ' is within the limit');
        }
        $this->set = [];

        $controller->execute();

        self::assertSame(429, $this->answer['code']);
        self::assertSame([], $this->set);
    }

    /**
     * The capture script writes the cookies itself when the request fails.
     */
    public function testACookieTheStoreCannotSetAnswers500(): void
    {
        $this->controller(['visitor_token' => self::ENGINE_TOKEN], failing: true)->execute();

        self::assertSame(500, $this->answer['code']);
    }

    public function testTheRouteIsCsrfExempt(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $controller = $this->controller([]);

        self::assertTrue($controller->validateForCsrf($request));
        self::assertNull($controller->createCsrfValidationException($request));
    }

    /**
     * @param array<string, mixed> $post
     */
    private function controller(
        array $post,
        bool $connected = true,
        bool $secure = true,
        bool $failing = false
    ): Land {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getPost')->willReturnCallback(static fn (string $name): mixed => $post[$name] ?? null);
        $request->method('isSecure')->willReturn($secure);

        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($raw) {
            $this->answer['code'] = $code;

            return $raw;
        });
        $raw->method('setContents')->willReturnCallback(function (string $contents) use ($raw) {
            $this->answer['contents'] = $contents;

            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($raw) {
            $this->answer['headers'][$name] = $value;

            return $raw;
        });
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_RAW)->willReturnCallback(function () use ($raw) {
            $this->answer = ['code' => 200, 'contents' => null, 'headers' => []];

            return $raw;
        });

        $settings = $this->createMock(Settings::class);
        $settings->method('isConnected')->willReturn($connected);

        $attributionManager = $this->createMock(AttributionManager::class);
        $attributionManager->method('getClientConfig')->willReturn(self::CLIENT_CONFIG);

        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $metadata) use ($failing): void {
                if ($failing) {
                    throw new CookieSizeLimitReachedException(new Phrase('too many cookies'));
                }
                $array = $metadata->__toArray();
                ksort($array);
                $this->set[$name] = ['value' => $value, 'metadata' => $array];
            }
        );
        $cookieManager->method('deleteCookie')->willReturnCallback(
            function (string $name, CookieMetadata $metadata): void {
                $this->deleted[$name] = $metadata->__toArray();
            }
        );
        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')
            ->willReturnCallback(static fn (): PublicCookieMetadata => new PublicCookieMetadata());

        $sessionConfig = $this->createMock(SessionConfig::class);
        $sessionConfig->method('getCookieDomain')->willReturn('.store.example');
        $sessionConfig->method('getCookiePath')->willReturn('/');

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cacheStore[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $value, string $key): bool {
            $this->cacheStore[$key] = $value;

            return true;
        });
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_760_000_000);
        $remoteAddress = $this->createMock(RemoteAddress::class);
        $remoteAddress->method('getRemoteAddress')->willReturn('203.0.113.7');

        return new Land(
            $request,
            $resultFactory,
            $settings,
            $attributionManager,
            $cookieManager,
            $metadataFactory,
            $sessionConfig,
            new PerAddressLimiter(new FixedWindowCounter($cache), $dateTime, $remoteAddress)
        );
    }
}
