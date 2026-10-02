<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Relay;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Relay\Index;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\BrowseEventValidator;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;

/**
 * PRO-3575: the storefront browse relay rate-limits by the connection's own
 * address and never holds a storefront request on the engine.
 */
class IndexTest extends TestCase
{
    private const BODY = '{"events":[{"event_id":"9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c",'
        . '"session_id":"s1","event_type":"product_view","sku":"ABC-1"}]}';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var int[] */
    private array $sleeps = [];

    /** @var array<string, string> */
    private array $cacheStore = [];

    private int $forwardedFor = 0;

    /** @var array{code: int, data: array<string, mixed>} */
    private array $answer = ['code' => 200, 'data' => []];

    public function testTheLimitHoldsWhateverForwardingHeaderTheClientSends(): void
    {
        $relay = $this->createRelay(array_fill(0, 40, new Response(200, [], '{"ok":true}')));

        for ($i = 0; $i < 30; $i++) {
            $relay->execute();
            self::assertSame(200, $this->answer['code'], 'request ' . ($i + 1) . ' is within the limit');
        }
        $relay->execute();

        self::assertSame(429, $this->answer['code']);
        self::assertCount(30, $this->history, 'the limited request never reaches the engine');
    }

    public function testASlowOrFailingEngineGetsOneShortAttemptAndNoWait(): void
    {
        $relay = $this->createRelay([new Response(503, [], '{"error":"unavailable"}')]);

        $relay->execute();

        self::assertSame(200, $this->answer['code'], 'browse events are loss-tolerant');
        self::assertCount(1, $this->history);
        self::assertSame([], $this->sleeps);
        $options = $this->history[0]['options'];
        self::assertSame(3, $options['timeout'] ?? null);
        self::assertSame(2, $options['connect_timeout'] ?? null);
    }

    public function testARequestedBackOffIsNeverWaitedOutInTheStorefrontRequest(): void
    {
        $relay = $this->createRelay([
            new Response(429, [], '{"error":"rate_limit_exceeded","retry_after_seconds":30}'),
        ]);

        $relay->execute();

        self::assertCount(1, $this->history);
        self::assertSame([], $this->sleeps);
    }

    public function testAnUnreachableEngineIsNotRetried(): void
    {
        $relay = $this->createRelay([
            new ConnectException('timed out', new Request('POST', 'https://engine.example/api/v1/ingest/browse')),
        ]);

        $relay->execute();

        self::assertSame(200, $this->answer['code']);
        self::assertSame([], $this->sleeps);
    }

    /**
     * @param array<int, Response|\Throwable> $responses
     */
    private function createRelay(array $responses): Index
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack) {
                $config['handler'] = $handlerStack;
                return new HttpClient($config);
            }
        );
        $sleeper = $this->createMock(SleeperInterface::class);
        $sleeper->method('sleep')->willReturnCallback(function (int $seconds): void {
            $this->sleeps[] = $seconds;
        });

        $settings = $this->createMock(Settings::class);
        $settings->method('isBrowseTrackingEnabled')->willReturn(true);
        $settings->method('isSendingAllowed')->willReturn(true);
        $settings->method('getApiKey')->willReturn('sk_test_key');
        $settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/browse');

        $client = new Client(
            $settings,
            $factory,
            $this->createMock(ProductMetadataInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Logger::class),
            $sleeper
        );

        // A client may put any value in the forwarding headers: each request
        // here claims a new address there.
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn(self::BODY);
        $request->method('getClientIp')->willReturnCallback(
            fn (): string => '198.51.100.' . (++$this->forwardedFor % 250)
        );
        $request->method('getServer')->willReturnCallback(
            static fn (?string $name = null, $default = null) => $name === 'REMOTE_ADDR' ? '203.0.113.7' : $default
        );

        $result = $this->createMock(Json::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($result) {
            $this->answer['code'] = $code;
            return $result;
        });
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->answer['data'] = $data;
            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturnCallback(function () use ($result) {
            $this->answer = ['code' => 200, 'data' => []];
            return $result;
        });

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key) => $this->cacheStore[$key] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $key): bool {
            $this->cacheStore[$key] = $data;
            return true;
        });

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_790_000_000);

        return new Index(
            $request,
            $jsonFactory,
            $settings,
            $client,
            new BrowseEventValidator(),
            $cache,
            $dateTime,
            $this->createMock(Logger::class),
            new RemoteAddress($request)
        );
    }
}
