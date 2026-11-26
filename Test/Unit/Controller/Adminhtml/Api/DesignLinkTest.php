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
use GuzzleHttp\Psr7\Response;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Api\AbstractJsonAction;
use Smaily\Connect\Controller\Adminhtml\Api\DesignLink;
use Smaily\Connect\Controller\Adminhtml\Api\Designs;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\PrivacyRequestBudget;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\StorefrontScript;

/**
 * CON-257: "Edit design" asks the server for a one-time link to an email's
 * design page (contract §22). The server checks the Settings ACL resource
 * and the form key, asks the engine with the store's API key, answers an
 * engine failure in the admin's words, and never keeps or logs the link.
 */
class DesignLinkTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $response = [];

    private int $status = 200;

    /** @var array<string, string> */
    private array $headers = [];

    public function testTheEndpointsNeedTheSettingsResource(): void
    {
        self::assertSame('Smaily_Connect::config', AbstractJsonAction::ADMIN_RESOURCE);
        self::assertSame('Smaily_Connect::config', DesignLink::ADMIN_RESOURCE);
        self::assertSame('Smaily_Connect::config', Designs::ADMIN_RESOURCE);
    }

    public function testALinkIsMintedForTheEmailAndAnsweredWithoutCaching(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::once())->method('designLink')
            ->with(
                'plugin.abandoned_cart',
                'https://shop.example/admin/smaily_connect/settings/',
                'et',
                true,
                'https://admin.shop.example'
            )
            ->willReturn('https://intelligence.smaily.com/design/email/dl_7Hc2');

        $this->designLink($emailDesigns, [
            'email_key' => 'plugin.abandoned_cart',
            'return_url' => 'https://shop.example/admin/smaily_connect/settings/',
            'display' => 'frame',
        ])->execute();

        self::assertSame(200, $this->status);
        self::assertSame(['url' => 'https://intelligence.smaily.com/design/email/dl_7Hc2'], $this->response);
        self::assertSame('no-store', $this->headers['Cache-Control'] ?? null);
    }

    /**
     * CON-366: only `display: 'frame'` asks for a frame link; "Open in a
     * new tab" and the tab of an admin that may not frame the page send
     * `tab`, and anything else counts as a tab too.
     *
     * @dataProvider tabDisplayProvider
     */
    public function testAnyOtherDisplayMintsATabLink(?string $display): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::once())->method('designLink')
            ->with('plugin.welcome', '', 'et', false, 'https://admin.shop.example')
            ->willReturn('https://intelligence.smaily.com/design/email/dl_1');

        $this->designLink($emailDesigns, ['email_key' => 'plugin.welcome'] + ($display === null ? [] : ['display' => $display]))
            ->execute();

        self::assertSame(200, $this->status);
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function tabDisplayProvider(): array
    {
        return ['tab' => ['tab'], 'none' => [null], 'unknown' => ['window']];
    }

    public function testAnInvalidFormKeyIsRefusedBeforeTheEngineIsAsked(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::never())->method('designLink');

        $this->designLink($emailDesigns, ['email_key' => 'plugin.welcome'], formKeyValid: false)->execute();

        self::assertSame(403, $this->status);
        self::assertSame([
            'error' => 'invalid_form_key',
            'message' => 'Your admin session has expired. Reload the page and try again.',
        ], $this->response);
        self::assertSame('no-store', $this->headers['Cache-Control'] ?? null);
    }

    public function testTheDesignListRefusesAnInvalidFormKeyToo(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::never())->method('designs');

        $this->designs($emailDesigns, formKeyValid: false)->execute();

        self::assertSame(403, $this->status);
        self::assertSame('invalid_form_key', $this->response['error']);
    }

    public function testNothingIsAskedWithoutCampaignIntelligence(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(false);
        $emailDesigns->expects(self::never())->method('designLink');
        $emailDesigns->expects(self::never())->method('designs');

        $this->designLink($emailDesigns, ['email_key' => 'plugin.welcome'])->execute();
        self::assertSame(503, $this->status);
        self::assertSame('not_available', $this->response['error']);

        $this->designs($emailDesigns)->execute();
        self::assertSame(503, $this->status);
        self::assertSame('not_available', $this->response['error']);
    }

    public function testAnEngineEmailNeedsTheIntelligenceAutomationsResource(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::never())->method('designLink');

        $this->designLink($emailDesigns, ['email_key' => 'replenish_due'], allowed: [])->execute();

        self::assertSame(403, $this->status);
        self::assertSame('forbidden', $this->response['error']);
    }

    public function testAnEngineEmailOpensForAnAdminWithThatResource(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::once())->method('designLink')->with('replenish_due')
            ->willReturn('https://intelligence.smaily.com/setup/automation/sl_1');

        $this->designLink($emailDesigns, ['email_key' => 'replenish_due'], allowed: [DesignLink::ENGINE_EMAIL_RESOURCE])
            ->execute();

        self::assertSame(['url' => 'https://intelligence.smaily.com/setup/automation/sl_1'], $this->response);
    }

    public function testAKeyThatIsNoEmailKeyIsNeverSentToTheEngine(): void
    {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->expects(self::never())->method('designLink');

        $this->designLink($emailDesigns, ['email_key' => '../store/uninstall'])->execute();

        self::assertSame(404, $this->status);
        self::assertSame('unknown_email', $this->response['error']);
    }

    /**
     * @dataProvider engineFailureProvider
     */
    public function testAnEngineFailureIsAnsweredInTheAdminsWords(
        EngineException $failure,
        int $status,
        string $error,
        string $message
    ): void {
        $emailDesigns = $this->createMock(EmailDesigns::class);
        $emailDesigns->method('isAvailable')->willReturn(true);
        $emailDesigns->method('designLink')->willThrowException($failure);

        $this->designLink($emailDesigns, ['email_key' => 'plugin.welcome'])->execute();

        self::assertSame($status, $this->status);
        self::assertSame(['error' => $error, 'message' => $message], $this->response);
        self::assertSame('no-store', $this->headers['Cache-Control'] ?? null);
    }

    /**
     * @return array<string, array{EngineException, int, string, string}>
     */
    public static function engineFailureProvider(): array
    {
        $retry = 'The design page could not be opened. Try again in a minute.';

        return [
            '404 unknown_email' => [
                new EngineRequestException('x', 404, ['error' => 'unknown_email']),
                404,
                'unknown_email',
                'Campaign Intelligence offers no design for this email.',
            ],
            '404 without the route' => [
                new EngineRequestException('x', 404, ['error' => 'not_found']),
                404,
                'not_available',
                'Campaign Intelligence does not offer email designs for this store yet.',
            ],
            '401 unauthorised' => [
                new EngineRequestException('x', 401, ['error' => 'unauthorised']),
                502,
                'api_key_rejected',
                'Campaign Intelligence did not accept this store\'s connection.'
                    . ' Connect again under Settings > Intelligence.',
            ],
            '403 tenant_inactive' => [
                new EngineRequestException('x', 403, ['error' => 'tenant_inactive']),
                502,
                'tenant_inactive',
                'Your Campaign Intelligence account is not active, so its automations cannot be read or saved.'
                    . ' Once Smaily tells you the account is active, press Check again under Settings > Intelligence.',
            ],
            '429 rate_limit_exceeded' => [
                new EngineTransportException('x', 429),
                429,
                'rate_limit_exceeded',
                'Too many design pages were opened in a short time. Try again in a minute.',
            ],
            '503 unavailable' => [new EngineTransportException('x', 503), 502, 'engine_unavailable', $retry],
            'no answer' => [new EngineTransportException('x'), 502, 'engine_unavailable', $retry],
            '422 frame_origin refused' => [
                new EngineRequestException('x', 422, ['error' => 'validation_failed', 'errors' => [
                    ['field' => 'frame_origin', 'message' => 'not in the registrable domain of the site'],
                ]]),
                400,
                'frame_origin_not_allowed',
                'The design page did not open.',
            ],
            '422 for another field' => [
                new EngineRequestException('x', 422, ['error' => 'validation_failed', 'errors' => [
                    ['field' => 'return_url', 'message' => 'must be https'],
                ]]),
                502,
                'engine_unavailable',
                $retry,
            ],
            'not an https link' => [
                new EngineRequestException('x', 502, ['error' => 'invalid_engine_response']),
                502,
                'invalid_engine_response',
                $retry,
            ],
        ];
    }

    /**
     * The whole path with the real engine client: the link reaches the
     * admin's browser and nothing else — no log line, no cache.
     */
    public function testTheLinkIsNeverLogged(): void
    {
        $link = 'https://intelligence.smaily.com/design/email/dl_secret_link';
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn(true);
        $settings->method('getApiKey')->willReturn('sk_test_key');
        $settings->method('getEndpoint')->willReturn(null);
        $settings->method('getEngineBaseUrl')->willReturn('https://intelligence.smaily.com');
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::never())->method(self::anything());

        $handlerStack = HandlerStack::create(new MockHandler([
            new Response(200, ['X-Engine-Version' => '1.21.0'], (string)json_encode([
                'url' => $link,
                'expires_at' => '2026-11-02T08:05:00.000Z',
            ])),
        ]));
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
            $settings,
            $httpClientFactory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $logger,
            $this->createMock(SleeperInterface::class),
            new StorefrontScript($storeManager, $this->createMock(HttpRequest::class)),
            $this->createMock(ConnectionHealth::class),
            $this->createMock(PrivacyRequestBudget::class)
        );

        $this->designLink(new EmailDesigns($settings, $client, $this->createMock(EmailDesignWorkflows::class)), ['email_key' => 'plugin.welcome'])->execute();

        self::assertSame(['url' => $link], $this->response);
        self::assertSame('no-store', $this->headers['Cache-Control'] ?? null);
    }

    /**
     * @param array<string, mixed> $body
     * @param string[] $allowed the ACL resources the admin holds
     */
    private function designLink(
        EmailDesigns $emailDesigns,
        array $body,
        bool $formKeyValid = true,
        array $allowed = ['Smaily_Connect::config']
    ): DesignLink {
        $locale = $this->createMock(ResolverInterface::class);
        $locale->method('getLocale')->willReturn('et_EE');

        return new DesignLink(
            $this->context($body, $allowed, $formKeyValid),
            $this->jsonFactory(),
            new JsonSerializer(),
            $emailDesigns,
            $locale
        );
    }

    private function designs(EmailDesigns $emailDesigns, bool $formKeyValid = true): Designs
    {
        return new Designs(
            $this->context([], ['Smaily_Connect::config'], $formKeyValid),
            $this->jsonFactory(),
            new JsonSerializer(),
            $emailDesigns
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param string[] $allowed
     */
    private function context(array $body, array $allowed, bool $formKeyValid = true): Context&MockObject
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        // The admin runs on a host of its own (CON-366).
        $request->method('getScheme')->willReturn('https');
        $request->method('getHttpHost')->willReturn('admin.shop.example');
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturnCallback(
            static fn (string $resource): bool => in_array($resource, $allowed, true)
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuthorization')->willReturn($authorization);
        $context->method('getFormKeyValidator')->willReturn($this->formKeyValidator($formKeyValid));

        return $context;
    }

    private function formKeyValidator(bool $valid): FormKeyValidator
    {
        $validator = $this->createMock(FormKeyValidator::class);
        $validator->method('validate')->willReturn($valid);

        return $validator;
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
