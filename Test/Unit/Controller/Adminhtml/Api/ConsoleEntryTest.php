<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Api;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Controller\Adminhtml\Api\ConsoleEntry;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;

/**
 * CON-230 (contract §24): "Open Campaign Intelligence" asks the server for
 * a one-time console link. The server checks the Settings ACL resource and
 * the form key, makes ONE engine call with the connection's API key and the
 * signed-in admin's own email, answers the link without caching, never
 * logs it, and answers an engine refusal by its status only — never the
 * engine's message.
 *
 * Driven through the real engine client against a mocked HTTP transport.
 */
class ConsoleEntryTest extends TestCase
{
    use EngineConnectionsStub;

    private const LINK = 'https://intelligence.smaily.com/console-entry#g=seg_secret_grant';

    /** The engine's own message, which an answer never quotes (CON-357). */
    private const ENGINE_MESSAGE = 'engine text naming owner@shop.example';

    /** @var array<int|string, mixed> */
    private array $response = [];

    private int $status = 200;

    /** @var array<string, string> */
    private array $headers = [];

    /** @var list<array{request: RequestInterface}> */
    private array $sent = [];

    private Settings&MockObject $settings;

    protected function setUp(): void
    {
        $this->settings = $this->createMock(Settings::class);
        $this->settings->method('isRefused')->willReturn(false);
        $this->settings->method('isSendingAllowed')->willReturn(true);
        $this->settings->method('getApiKey')->willReturn('sk_connection_key');
        $this->settings->method('getEngineBaseUrl')->willReturn('https://intelligence.smaily.com');
    }

    public function testTheEndpointNeedsTheSettingsResource(): void
    {
        self::assertSame('Smaily_Connect::config', ConsoleEntry::ADMIN_RESOURCE);
    }

    /**
     * One press, one §24 call: the connection's key, the admin's own email
     * as the whole body; the link goes to the browser and nowhere else.
     */
    public function testOnePressMakesOneCallWithTheAdminsEmailAndAnswersTheLink(): void
    {
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::never())->method(self::anything());

        $this->consoleEntry([$this->linkAnswer()], logger: $logger)->execute();

        self::assertCount(1, $this->sent);
        $request = $this->sent[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://intelligence.smaily.com/api/v1/console/entry', (string)$request->getUri());
        self::assertSame('Bearer sk_connection_key', $request->getHeaderLine('Authorization'));
        self::assertSame(['email' => 'owner@shop.example'], json_decode((string)$request->getBody(), true));
        self::assertSame(200, $this->status);
        self::assertSame(['url' => self::LINK], $this->response);
        self::assertSame('no-store, private', $this->headers['Cache-Control'] ?? null);
    }

    public function testTheEndpointsMapAddressIsUsedWhenTheEngineGaveOne(): void
    {
        $this->settings->method('getEndpoint')->willReturnCallback(
            static fn (string $key): ?string => $key === 'console_entry'
                ? 'https://intelligence.smaily.com/api/v1/console/entry?v=2'
                : null
        );

        $this->consoleEntry([$this->linkAnswer()])->execute();

        self::assertSame(
            'https://intelligence.smaily.com/api/v1/console/entry?v=2',
            (string)$this->sent[0]['request']->getUri()
        );
    }

    public function testAnInvalidFormKeyIsRefusedBeforeTheEngineIsAsked(): void
    {
        $this->consoleEntry([$this->linkAnswer()], formKeyValid: false)->execute();

        self::assertCount(0, $this->sent);
        self::assertSame(403, $this->status);
        self::assertSame([
            'error' => 'invalid_form_key',
            'message' => 'Your admin session has expired. Reload the page and try again.',
        ], $this->response);
        self::assertSame('no-store, private', $this->headers['Cache-Control'] ?? null);
    }

    public function testNothingIsAskedWithoutAConnection(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(false);
        $this->settings = $settings;

        $this->consoleEntry([$this->linkAnswer()])->execute();

        self::assertCount(0, $this->sent);
        self::assertSame(503, $this->status);
        self::assertSame('not_available', $this->response['error']);
    }

    public function testARefusedAccountIsAnsweredWithoutAsking(): void
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isRefused')->willReturn(true);
        $this->settings = $settings;

        $this->consoleEntry([$this->linkAnswer()])->execute();

        self::assertCount(0, $this->sent);
        self::assertSame(403, $this->status);
        self::assertSame([
            'error' => 'tenant_inactive',
            'message' => 'This store is no longer active in Campaign Intelligence.',
        ], $this->response);
    }

    public function testAnAdminWithoutAValidEmailIsNotSent(): void
    {
        $this->consoleEntry([$this->linkAnswer()], adminEmail: 'not-an-email')->execute();

        self::assertCount(0, $this->sent);
        self::assertSame(400, $this->status);
        self::assertSame('no_admin_email', $this->response['error']);
    }

    /**
     * @dataProvider engineRefusalProvider
     * @param array<string, mixed> $expected
     */
    public function testAnEngineRefusalIsAnsweredByItsStatusOnly(Response $answer, int $status, array $expected): void
    {
        $this->consoleEntry([$answer, $this->linkAnswer()])->execute();

        self::assertCount(1, $this->sent, 'One attempt: no retry, no wait');
        self::assertSame($status, $this->status);
        self::assertSame($expected, $this->response);
        self::assertStringNotContainsString(self::ENGINE_MESSAGE, (string)json_encode($this->response));
        self::assertSame('no-store, private', $this->headers['Cache-Control'] ?? null);
    }

    /**
     * @return array<string, array{Response, int, array<string, mixed>}>
     */
    public static function engineRefusalProvider(): array
    {
        $retry = 'Campaign Intelligence could not be opened. Try again in a minute.';

        return [
            '404 console entry switched off' => [self::refusal(404, 'not_found'), 404, [
                'error' => 'not_available',
                'message' => 'Opening Campaign Intelligence from the extension is not available yet.'
                    . ' Open the console and sign in there:',
                'console_url' => 'https://intelligence.smaily.com/login',
            ]],
            '401 unauthorised' => [self::refusal(401, 'unauthorised'), 502, [
                'error' => 'api_key_rejected',
                'message' => 'Campaign Intelligence did not accept this store\'s connection.'
                    . ' Connect again under Settings > Intelligence.',
            ]],
            '403 tenant_inactive' => [self::refusal(403, 'tenant_inactive'), 403, [
                'error' => 'tenant_inactive',
                'message' => 'This store is no longer active in Campaign Intelligence.',
            ]],
            '429 rate_limit_exceeded' => [self::refusal(429, 'rate_limit_exceeded'), 429, [
                'error' => 'rate_limit_exceeded',
                'message' => 'Too many attempts. Try again in a minute.',
            ]],
            '400 validation_failed' => [self::refusal(400, 'validation_failed'), 502, [
                'error' => 'engine_unavailable',
                'message' => $retry,
            ]],
            '500 db_failure' => [self::refusal(500, 'db_failure'), 502, ['error' => 'engine_unavailable', 'message' => $retry]],
            'not an https link' => [
                new Response(200, [], (string)json_encode(['url' => 'http://elsewhere.example/x'])),
                502,
                ['error' => 'invalid_engine_response', 'message' => $retry],
            ],
        ];
    }

    private static function refusal(int $status, string $error): Response
    {
        return new Response($status, [], (string)json_encode(['error' => $error, 'message' => self::ENGINE_MESSAGE]));
    }

    private function linkAnswer(): Response
    {
        return new Response(200, ['X-Engine-Version' => '1.34.0'], (string)json_encode([
            'url' => self::LINK,
            'expires_at' => '2026-11-20T09:05:00.000Z',
        ]));
    }

    /**
     * The controller with the real engine client of the primary connection,
     * whose transport answers $answers in turn and records each request.
     *
     * @param Response[] $answers
     */
    private function consoleEntry(
        array $answers,
        bool $formKeyValid = true,
        string $adminEmail = ' owner@shop.example ',
        ?Logger $logger = null
    ): ConsoleEntry {
        $this->sent = [];
        $handlerStack = HandlerStack::create(new MockHandler($answers));
        $handlerStack->push(Middleware::history($this->sent));
        $httpClientFactory = $this->createMock(HttpClientFactory::class);
        $httpClientFactory->method('create')->willReturnCallback(
            static fn (array $config) => new HttpClient(['handler' => $handlerStack] + $config)
        );
        $store = $this->createMock(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getBaseUrl')->willReturn('https://shop.example/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($store);
        $client = new Client(
            $this->settings,
            $httpClientFactory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $logger ?? $this->createMock(Logger::class),
            $this->createMock(SleeperInterface::class),
            new StorefrontScript($storeManager, $this->createMock(HttpRequest::class)),
            $this->createMock(ConnectionHealth::class)
        );

        return new ConsoleEntry(
            $this->context($formKeyValid, $adminEmail),
            $this->jsonFactory(),
            new JsonSerializer(),
            $this->oneEngineConnection($this->settings, $client)
        );
    }

    private function context(bool $formKeyValid, string $adminEmail): Context
    {
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn(new DataObject(['email' => $adminEmail]));
        $validator = $this->createMock(FormKeyValidator::class);
        $validator->method('validate')->willReturn($formKeyValid);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->createMock(HttpRequest::class));
        $context->method('getAuth')->willReturn($auth);
        $context->method('getFormKeyValidator')->willReturn($validator);

        return $context;
    }

    private function jsonFactory(): JsonFactory
    {
        $this->response = [];
        $this->status = 200;
        $this->headers = [];
        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($result) {
            $this->status = $code;

            return $result;
        });
        $result->method('setHeader')->willReturnCallback(function (string $name, string $value) use ($result) {
            $this->headers[$name] = $value;

            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        return $jsonFactory;
    }
}
