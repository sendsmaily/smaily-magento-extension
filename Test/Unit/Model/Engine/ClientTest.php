<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;

class ClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var int[] */
    private array $sleeps = [];

    private Settings&MockObject $settings;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('getApiKey')->willReturn('sk_test_key');
    }

    public function testIngestSendsWrapperAndBearerAuth(): void
    {
        $this->settings->method('getEndpoint')->with('ingest_orders')
            ->willReturn('https://engine.example/api/v1/ingest/orders');
        $client = $this->createClient([
            new Response(200, [], '{"ok":true,"processed":1,"deduplicated":0,"errors":[]}'),
        ]);

        $response = $client->ingest(Client::DOMAIN_ORDERS, [['external_order_id' => '100000001']]);

        self::assertTrue($response['ok']);
        $request = $this->history[0]['request'];
        self::assertSame('Bearer sk_test_key', $request->getHeaderLine('Authorization'));
        self::assertStringContainsString('"orders":[{"external_order_id":"100000001"}]', (string)$request->getBody());
    }

    public function testTheLastExchangeHoldsTheBodyAsSentAndTheFinalReply(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/orders');
        $client = $this->createClient([
            new Response(503, [], '{"error":"unavailable"}'),
            new Response(200, [], '{"ok":true,"processed":1}'),
        ]);
        self::assertNull($client->lastExchange(), 'Nothing was sent yet');

        $client->ingest(Client::DOMAIN_ORDERS, [['external_order_id' => '100000001']]);

        self::assertSame([
            'request' => ['orders' => [['external_order_id' => '100000001']]],
            'response' => ['http_status' => 200, 'body' => ['ok' => true, 'processed' => 1]],
        ], $client->lastExchange());
        self::assertStringNotContainsString('sk_test_key', (string)json_encode($client->lastExchange()));
    }

    public function testARefusalIsRecordedAsTheEngineAnsweredIt(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/catalog');
        $client = $this->createClient([new Response(400, [], '{"error":"validation_failed"}')]);

        try {
            $client->ingest(Client::DOMAIN_CATALOG, []);
        } catch (EngineRequestException) {
        }

        self::assertSame(
            ['http_status' => 400, 'body' => ['error' => 'validation_failed']],
            $client->lastExchange()['response'] ?? null
        );
    }

    public function testRateLimitRetriesWithRetryAfterFromBody(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $client = $this->createClient([
            new Response(429, [], '{"error":"rate_limit_exceeded","retry_after_seconds":7}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $response = $client->ping();

        self::assertTrue($response['ok']);
        self::assertSame([7], $this->sleeps);
        self::assertCount(2, $this->history);
    }

    /**
     * PRO-3575: a back-off the engine asks for is honoured up to a fixed
     * ceiling, so no caller ever waits on it for longer than that.
     */
    public function testARequestedBackOffIsHonouredUpToAFixedCeiling(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $client = $this->createClient([
            new Response(429, [], '{"error":"rate_limit_exceeded","retry_after_seconds":3600}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $client->ping();

        self::assertSame([60], $this->sleeps);
    }

    /**
     * PRO-3575: every engine call bounds the connection phase as well as the
     * whole request.
     */
    public function testEveryCallBoundsTheConnectionAndTheRequest(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $client = $this->createClient([new Response(200, [], '{"ok":true}')]);

        $client->ping();

        $options = $this->history[0]['options'];
        self::assertSame(10, $options['connect_timeout'] ?? null);
        self::assertSame(30, $options['timeout'] ?? null);
    }

    public function testServerErrorsRetryThenThrowTransportException(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $responses = array_fill(0, 6, new Response(503, [], '{"error":"unavailable"}'));
        $client = $this->createClient($responses);

        $this->expectException(EngineTransportException::class);
        try {
            $client->ping();
        } finally {
            self::assertSame([1, 2, 4, 8, 16], $this->sleeps);
        }
    }

    public function testClientErrorNeverRetries(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/catalog');
        $client = $this->createClient([
            new Response(400, [], '{"error":"validation_failed","message":"bad wrapper"}'),
        ]);

        try {
            $client->ingest(Client::DOMAIN_CATALOG, []);
            self::fail('Expected EngineRequestException');
        } catch (EngineRequestException $exception) {
            self::assertSame(400, $exception->getHttpStatus());
            self::assertSame('validation_failed', $exception->getErrorBody()['error']);
            self::assertSame([], $this->sleeps);
        }
    }

    /**
     * Contract §2: a `403 tenant_inactive` from ANY authenticated endpoint
     * means the key is valid and the account is not. Recorded here, at the
     * one chokepoint every engine call passes through (PRO-2451).
     */
    public function testTenantInactiveRefusalIsRecordedOnce(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/orders');
        $this->settings->expects(self::once())->method('recordRefusal');
        $client = $this->createClient([
            new Response(403, [], '{"error":"tenant_inactive","message":"This tenant is currently deactivated.'
                . ' Contact engine administrator.","tenant_status":"suspended"}'),
        ]);

        $this->expectException(EngineRequestException::class);
        $client->ingest(Client::DOMAIN_ORDERS, [['external_order_id' => '1']]);
    }

    public function testOtherForbiddenResponsesAreNotTreatedAsARefusal(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/orders');
        $this->settings->expects(self::never())->method('recordRefusal');
        $client = $this->createClient([
            new Response(403, [], '{"error":"type_not_externally_allowed","message":"internal only"}'),
        ]);

        $this->expectException(EngineRequestException::class);
        $client->ingest(Client::DOMAIN_ORDERS, [['external_order_id' => '1']]);
    }

    /**
     * The account answered, so a remembered refusal is over — the health-check
     * ping and the admin's "Check again" both recover through this one line.
     */
    public function testASuccessfulAuthenticatedCallClearsTheRefusal(): void
    {
        $this->settings->method('getEndpoint')->willReturn('https://engine.example/api/v1/ingest/ping');
        $this->settings->expects(self::once())->method('clearRefusal');
        $client = $this->createClient([new Response(200, [], '{"ok":true,"pong":true}')]);

        $client->ping();
    }

    public function testSetupExchangeDoesNotClearTheRefusalItself(): void
    {
        // Unauthenticated: Settings::storeExchange() owns that half, so a
        // failed persist can never leave a cleared refusal behind.
        $this->settings->expects(self::never())->method('clearRefusal');
        $client = $this->createClient([
            new Response(200, [], '{"tenant_id":"t1","api_key":"sk_x","endpoints":{}}'),
        ]);

        $client->setupExchange('tok_abc123');
    }

    public function testCatalogRemoveSendsProductIdsWrapperToMappedEndpoint(): void
    {
        $this->settings->method('getEndpoint')->with('ingest_catalog_remove')
            ->willReturn('https://engine.example/api/v1/ingest/catalog/remove');
        $client = $this->createClient([
            new Response(200, [], '{"ok":true,"removed_products":1,"rows_tombstoned":2,"not_found":[]}'),
        ]);

        $response = $client->catalogRemove(['7620134', '7620135']);

        self::assertSame(1, $response['removed_products']);
        $request = $this->history[0]['request'];
        self::assertSame('https://engine.example/api/v1/ingest/catalog/remove', (string)$request->getUri());
        self::assertSame('Bearer sk_test_key', $request->getHeaderLine('Authorization'));
        self::assertSame('{"product_ids":["7620134","7620135"]}', (string)$request->getBody());
    }

    public function testCatalogRemoveFallsBackToHardcodedPathWhenMapLacksTheKey(): void
    {
        // Tenants exchanged before contract v1.4.0 have no
        // ingest_catalog_remove in their endpoints map (§1 "map age") — the
        // hardcoded §3b path is the load-bearing fallback (mirrors Woo).
        $this->settings->method('getEndpoint')->willReturn(null);
        $this->settings->method('getEngineBaseUrl')->willReturn('https://engine.example/');
        $client = $this->createClient([
            new Response(200, [], '{"ok":true,"removed_products":0,"rows_tombstoned":0,"not_found":["9"]}'),
        ]);

        $response = $client->catalogRemove(['9']);

        self::assertSame(['9'], $response['not_found']);
        self::assertSame(
            'https://engine.example/api/v1/ingest/catalog/remove',
            (string)$this->history[0]['request']->getUri()
        );
    }

    public function testCustomerDeleteSubstitutesEmailPlaceholderAndTreats404AsSuccess(): void
    {
        $this->settings->method('getEndpoint')->with('customer_delete')
            ->willReturn('https://engine.example/api/v1/customer/{email}');
        $client = $this->createClient([new Response(404, [], '{"error":"not_found"}')]);

        $response = $client->customerDelete('Kati Käbi@example.com');

        self::assertTrue($response['already_deleted']);
        $path = (string)$this->history[0]['request']->getUri();
        self::assertStringNotContainsString('{email}', $path);
        self::assertStringContainsString('kati%20k%C3%A4bi%40example.com', $path);
    }

    /**
     * PRO-3572: the customer endpoints carry the address in the path, and
     * Guzzle's network-failure message ends with the full request URL. The
     * message is logged, stored on queue rows and shown in the admin.
     */
    public function testANetworkFailureOnACustomerEndpointCarriesNoAddress(): void
    {
        $this->settings->method('getEndpoint')->with('customer_opt_out')
            ->willReturn('https://engine.example/api/v1/customer/{email}/opt-out?source=magento');
        $failure = static fn (RequestInterface $request) => new ConnectException(
            'cURL error 7: Failed to connect to engine.example port 443 after 3 ms: Could not connect to server '
            . '(see https://curl.se/libcurl/c/libcurl-errors.html) for ' . $request->getUri(),
            $request
        );
        $client = $this->createClient(array_fill(0, 6, $failure));

        try {
            $client->customerOptOut('person@example.com', true, 'user_preference', '2026-10-02T00:00:00Z');
            self::fail('Expected an EngineTransportException');
        } catch (EngineTransportException $exception) {
            self::assertStringContainsString('cURL error 7', $exception->getMessage());
            self::assertStringContainsString(
                'https://engine.example/api/v1/customer/{email}/opt-out',
                $exception->getMessage()
            );
            for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
                self::assertStringNotContainsString('person', $link->getMessage());
                self::assertStringNotContainsString('source=', $link->getMessage());
            }
        }
    }

    public function testSetupExchangeParsesFullUrlInput(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"tenant_id":"t1","api_key":"sk_x","endpoints":{}}'),
        ]);

        $response = $client->setupExchange('https://intelligence.smaily.com/setup/tok_abc123');

        self::assertSame('t1', $response['tenant_id']);
        $request = $this->history[0]['request'];
        self::assertSame('https://intelligence.smaily.com/api/setup/exchange', (string)$request->getUri());
        $body = json_decode((string)$request->getBody(), true);
        self::assertSame('tok_abc123', $body['setup_token']);
        self::assertSame('magento', $body['plugin_info']['platform']);
        // Setup exchange is the only unauthenticated endpoint.
        self::assertSame('', $request->getHeaderLine('Authorization'));
    }

    public function testABareSetupTokenIsExchangedAtTheSmailyEngine(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"tenant_id":"t1","api_key":"sk_x","endpoints":{}}'),
        ]);

        $client->setupExchange('tok_abc123');

        self::assertSame(
            'https://intelligence.smaily.com/api/setup/exchange',
            (string)$this->history[0]['request']->getUri()
        );
    }

    /**
     * An explicit port on the Smaily engine host is kept (mirrors Woo
     * parse_setup_url).
     */
    public function testSetupExchangeKeepsAnExplicitPort(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"tenant_id":"t1","api_key":"sk_x","endpoints":{}}'),
        ]);

        $client->setupExchange('https://intelligence.smaily.com:8443/setup/tok_dev');

        $request = $this->history[0]['request'];
        self::assertSame('https://intelligence.smaily.com:8443/api/setup/exchange', (string)$request->getUri());
        $body = json_decode((string)$request->getBody(), true);
        self::assertSame('tok_dev', $body['setup_token']);
    }

    /**
     * PRO-3575: the setup address must be an https address on the Smaily
     * engine host; any other address is refused before a request is made.
     *
     * @dataProvider refusedSetupAddressProvider
     */
    public function testASetupAddressOutsideTheSmailyEngineIsRefusedWithoutARequest(string $setupUrl): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"tenant_id":"t1","api_key":"sk_x","endpoints":{}}'),
        ]);

        try {
            $client->setupExchange($setupUrl);
            self::fail('Expected an EngineRequestException');
        } catch (EngineRequestException $exception) {
            self::assertSame(
                'The setup URL must be an https address on intelligence.smaily.com.',
                $exception->getMessage()
            );
        }
        self::assertSame([], $this->history);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedSetupAddressProvider(): array
    {
        return [
            'another host' => ['https://engine.example/setup/tok_abc123'],
            'plain http' => ['http://intelligence.smaily.com/setup/tok_abc123'],
            'an IP address over http' => ['http://172.20.0.1:9876/setup/tok_dev'],
            'a look-alike host' => ['https://intelligence.smaily.com.engine.example/setup/tok_abc123'],
            'user info before another host' => ['https://intelligence.smaily.com@engine.example/setup/tok_abc123'],
        ];
    }

    /**
     * PRO-3575: the engine's reply is returned for storing only when the
     * engine base URL and every endpoint are https addresses on the Smaily
     * engine host.
     *
     * @dataProvider refusedReplyProvider
     */
    public function testAReplyNamingAnAddressOutsideTheSmailyEngineIsRefused(string $reply): void
    {
        $client = $this->createClient([new Response(200, [], $reply)]);

        $this->expectException(EngineRequestException::class);
        $this->expectExceptionMessage(
            'The engine answered with an address that is not an https address on intelligence.smaily.com,'
            . ' so the connection was not saved.'
        );
        $client->setupExchange('https://intelligence.smaily.com/setup/tok_abc123');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedReplyProvider(): array
    {
        $tenant = '"tenant_id":"t1","api_key":"sk_x"';
        $ping = '"ingest_ping":"https://intelligence.smaily.com/api/v1/ingest/ping"';

        return [
            'an endpoint on another host' => [
                '{' . $tenant . ',"endpoints":{' . $ping
                . ',"ingest_orders":"https://engine.example/api/v1/ingest/orders"}}',
            ],
            'an endpoint over plain http' => [
                '{' . $tenant . ',"endpoints":{"ingest_ping":"http://intelligence.smaily.com/api/v1/ingest/ping"}}',
            ],
            'an endpoint that is not a string' => ['{' . $tenant . ',"endpoints":{"ingest_ping":["x"]}}'],
            'an endpoints value that is not a map' => ['{' . $tenant . ',"endpoints":"https://engine.example"}'],
            'the engine base URL on another host' => [
                '{' . $tenant . ',"engine_base_url":"https://engine.example","endpoints":{' . $ping . '}}',
            ],
        ];
    }

    public function testAReplyOnTheSmailyEngineIsReturnedForStoring(): void
    {
        $reply = [
            'tenant_id' => 't1',
            'api_key' => 'sk_x',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'endpoints' => [
                'ingest_ping' => 'https://intelligence.smaily.com/api/v1/ingest/ping',
                'customer_export' => 'https://intelligence.smaily.com/api/v1/customer/{email}/export',
            ],
        ];
        $client = $this->createClient([new Response(200, [], (string)json_encode($reply))]);

        self::assertSame($reply, $client->setupExchange('https://intelligence.smaily.com/setup/tok_abc123'));
    }

    /**
     * @param array<int, Response|callable> $responses
     */
    private function createClient(array $responses): Client
    {
        $this->history = [];
        $this->sleeps = [];

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

        $productMetadata = $this->createMock(ProductMetadataInterface::class);
        $productMetadata->method('getVersion')->willReturn('2.4.8');

        return new Client(
            $this->settings,
            $factory,
            $productMetadata,
            $this->createMock(StoreManagerInterface::class),
            $this->createMock(Logger::class),
            $sleeper
        );
    }
}
