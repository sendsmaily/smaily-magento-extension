<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Controller\Adminhtml\Automations\TestMode;
use Smaily\Connect\Controller\Adminhtml\Automations\TestSend;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\EmailTestMode;
use Smaily\Connect\Model\Engine\PrivacyRequestBudget;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-458: the admin endpoints behind a Campaign Intelligence email's
 * "Send test email" (contract §19) and "Test mode" switch (§20), on the
 * connection stored in the database and the real engine client, its HTTP
 * answered by a faked engine — no request leaves the test. Each call goes
 * to the endpoint the connection's map names, then the card's state is
 * read again (§12). The admin is named by user id only.
 */
class EmailTestModeEndpointsTest extends IntegrationTestCase
{
    private const MAP = 'https://intelligence.smaily.com/api/v1/automations/{key}/';

    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    /** @var array<int|string, mixed> */
    private array $response = [];

    private int $status = 200;

    public function testATestSendGoesToTheMappedEndpointWithTheTypedAddress(): void
    {
        $settings = $this->connectedSettings();

        $this->testSend($settings, ['email_key' => 'replenish_due', 'email' => ' owner@shop.example '], [
            new Response(200, [], '{"ok":true,"trigger_key":"replenish_due"}'),
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'test']),
        ])->execute();

        self::assertSame(200, $this->status);
        self::assertSame([
            'ok' => true,
            'message' => 'Smaily accepted the test email. Check the inbox.',
            'state' => ['test_mode' => true, 'mode' => 'test', 'status' => '', 'notice' => ''],
        ], $this->response);
        self::assertSame([
            'POST https://intelligence.smaily.com/api/v1/automations/replenish_due/test-send',
            'GET https://intelligence.smaily.com/api/v1/automations/config',
        ], $this->requests());
        self::assertSame('{"email":"owner@shop.example"}', (string)$this->history[0]['request']->getBody());
        self::assertStringStartsWith('Bearer sk_', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testANotReadyEmailIsExplainedAndAskedOnce(): void
    {
        $this->testSend($this->connectedSettings(), ['email_key' => 'replenish_due', 'email' => 'a@shop.example'], [
            new Response(409, [], '{"error":"not_ready","details":{"reasons":["missing_subject"]}}'),
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'off']),
        ])->execute();

        self::assertSame(409, $this->status);
        self::assertSame('The test email cannot be sent yet:', $this->response['message']);
        self::assertSame(['The email has no subject'], $this->response['reasons']);
        self::assertCount(1, array_filter($this->requests(), static fn (string $r): bool => str_contains($r, 'test-send')));
    }

    public function testTurningTestModeOffSendsTheAdminsUserIdOnly(): void
    {
        $this->testMode($this->connectedSettings(), ['email_key' => 'replenish_due', 'test_mode' => false], [
            new Response(200, [], '{"ok":true,"changed":true,"setup_state":"live","daily_limit":10}'),
            $this->configAnswer([
                'trigger_key' => 'replenish_due',
                'setup_state' => 'live',
                'daily_limit' => 10,
                'notice' => 'starts_when_sender_verified',
            ]),
        ])->execute();

        self::assertSame(200, $this->status);
        self::assertSame([
            'test_mode' => false,
            'mode' => 'active',
            'status' => 'Sending to real customers · up to 10 emails today',
            'notice' => 'Starts when your sender domain is verified in Smaily',
        ], $this->response['state']);
        self::assertSame(
            'POST https://intelligence.smaily.com/api/v1/automations/replenish_due/go-live',
            $this->requests()[0]
        );
        $sent = (string)$this->history[0]['request']->getBody();
        self::assertSame('{"live":true,"confirmed":true,"switched_by":"17"}', $sent);
        self::assertStringNotContainsString('admin@shop.example', $sent);
    }

    public function testTurningTestModeOnAgainAsksNoConfirmation(): void
    {
        $this->testMode($this->connectedSettings(), ['email_key' => 'replenish_due', 'test_mode' => true], [
            new Response(200, [], '{"ok":true,"changed":true,"setup_state":"test"}'),
            $this->configAnswer(['trigger_key' => 'replenish_due', 'setup_state' => 'test']),
        ])->execute();

        self::assertSame('{"live":false}', (string)$this->history[0]['request']->getBody());
        self::assertTrue($this->response['state']['test_mode']);
    }

    /**
     * A store-run or transactional email has neither control (§19, §20 do
     * not take its key), and nothing is asked without a connection or a
     * valid form key.
     *
     * @dataProvider refusedRequests
     * @param array<string, mixed> $body
     */
    public function testNothingIsAskedOfTheEngineForARefusedRequest(
        array $body,
        bool $connected,
        bool $formKeyValid,
        int $status
    ): void {
        $settings = $connected ? $this->connectedSettings() : $this->settings();

        $this->testMode($settings, $body, [], $formKeyValid)->execute();

        self::assertSame($status, $this->status);
        self::assertSame([], $this->history);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, bool, int}>
     */
    public static function refusedRequests(): array
    {
        return [
            'a store-run email' => [['email_key' => 'plugin.welcome', 'test_mode' => false], true, true, 404],
            'a transactional email' => [
                ['email_key' => 'transactional.order_confirmation', 'test_mode' => false],
                true,
                true,
                404,
            ],
            'not connected' => [['email_key' => 'replenish_due', 'test_mode' => false], false, true, 503],
            'an expired form key' => [['email_key' => 'replenish_due', 'test_mode' => false], true, false, 403],
        ];
    }

    private function settings(): Settings
    {
        return $this->objectManager->create(Settings::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, []),
            'configWriter' => $this->objectManager->get(WriterInterface::class),
            'cacheTypeList' => $this->createMock(TypeListInterface::class),
        ]);
    }

    /**
     * Settings of a connection whose §1 map names the test send and the
     * real-sends switch, stored in the database.
     */
    private function connectedSettings(): Settings
    {
        $settings = $this->settings();
        $settings->storeExchange([
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'tenant_name' => 'Shop',
            'api_key' => 'sk_8f3k2a4e1c4d8a9b2f7e3d1a6c8b9e0f',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'engine_version' => '1.37.2',
            'endpoints' => [
                'automations_config' => 'https://intelligence.smaily.com/api/v1/automations/config',
                'automations_test_send' => self::MAP . 'test-send',
                'automations_go_live' => self::MAP . 'go-live',
            ],
            'config' => [],
            'issued_at' => '2026-11-26T10:15:23Z',
        ], 'shop');

        return $settings;
    }

    /**
     * @param array<string, mixed> $body
     * @param Response[] $responses
     */
    private function testSend(Settings $settings, array $body, array $responses): TestSend
    {
        return new TestSend(
            $this->context($body, true),
            $this->jsonFactory(),
            new JsonSerializer(),
            new EmailTestMode($this->client($settings, $responses)),
            $settings
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param Response[] $responses
     */
    private function testMode(Settings $settings, array $body, array $responses, bool $formKeyValid = true): TestMode
    {
        return new TestMode(
            $this->context($body, $formKeyValid),
            $this->jsonFactory(),
            new JsonSerializer(),
            new EmailTestMode($this->client($settings, $responses)),
            $settings
        );
    }

    /**
     * The real engine client of these settings, its HTTP answered in turn.
     *
     * @param Response[] $responses
     */
    private function client(Settings $settings, array $responses): Client
    {
        $this->history = [];
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $config): HttpClient => new HttpClient(['handler' => $handlerStack] + $config)
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);

        return new Client(
            $settings,
            $factory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $this->createMock(Logger::class),
            $this->createMock(SleeperInterface::class),
            new StorefrontScript($storeManager, $this->createMock(Http::class)),
            $this->createMock(ConnectionHealth::class),
            $this->createMock(PrivacyRequestBudget::class)
        );
    }

    /**
     * An admin request with a JSON body, signed in as admin user 17.
     *
     * @param array<string, mixed> $body
     */
    private function context(array $body, bool $formKeyValid): Context
    {
        $request = $this->createMock(Http::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        $validator = $this->createMock(FormKeyValidator::class);
        $validator->method('validate')->willReturn($formKeyValid);
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn(new DataObject(['user_id' => 17, 'email' => 'admin@shop.example']));
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getFormKeyValidator')->willReturn($validator);
        $context->method('getAuth')->willReturn($auth);

        return $context;
    }

    private function jsonFactory(): JsonFactory
    {
        $this->response = [];
        $this->status = 200;
        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $result->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($result) {
            $this->status = $code;

            return $result;
        });
        $result->method('setHeader')->willReturnSelf();
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        return $jsonFactory;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function configAnswer(array $item): Response
    {
        return new Response(200, [], (string)json_encode(['configs' => [], 'setup' => [$item]]));
    }

    /**
     * @return string[]
     */
    private function requests(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['request']->getMethod() . ' ' . $entry['request']->getUri(),
            $this->history
        );
    }
}
