<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Api\TestSmaily;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Test connection names the cause of a refusal (PRO-3579): a package
 * without API access is not called a credential refusal.
 */
class TestSmailyTest extends TestCase
{
    private const PLAN_MESSAGE = 'Smaily refused the request because this account\'s package does not include API access.'
        . ' Upgrade the package in Smaily to connect — until then the credentials cannot be checked at all.';

    /** @var array<int|string, mixed> */
    private array $response = [];

    public function testAPackageWithoutApiAccessIsNamedAsTheCause(): void
    {
        $this->pressTestConnection(new PlanBlockedException(self::PLAN_MESSAGE, 403));

        self::assertFalse($this->response['connected']);
        self::assertSame(self::PLAN_MESSAGE, $this->response['error']);
    }

    public function testRefusedCredentialsAreStillCalledRefused(): void
    {
        $this->pressTestConnection(new AuthenticationException('Smaily API credentials were rejected', 401));

        self::assertFalse($this->response['connected']);
        self::assertSame(
            'Smaily rejected these credentials. Check the subdomain, username and password.',
            $this->response['error']
        );
    }

    /**
     * PRO-3570: the Connection status follows a test only when Smaily was
     * asked — an answer, a refusal or no answer at all.
     *
     * @dataProvider askedProvider
     */
    public function testATestThatAskedSmailyIsMarkedChecked(?\Throwable $refusal): void
    {
        $this->pressTestConnection($refusal);

        self::assertTrue($this->response['checked']);
        self::assertSame($refusal === null, $this->response['connected']);
    }

    /**
     * @return array<string, array{?\Throwable}>
     */
    public static function askedProvider(): array
    {
        return [
            'accepted' => [null],
            'refused' => [new AuthenticationException('refused', 401)],
            'a package without API access' => [new PlanBlockedException(self::PLAN_MESSAGE, 403)],
            'unreachable' => [new SmailyClientException('timeout')],
        ];
    }

    /**
     * PRO-3575: a subdomain that would change the request host is refused
     * before any client is built — neither the posted nor the stored
     * password is sent.
     *
     * @dataProvider refusedBodyProvider
     * @param array<string, mixed> $body
     */
    public function testASubdomainThatIsNotPlainIsRefusedWithoutARequest(array $body): void
    {
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->expects(self::never())->method('create');
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

        $this->controller($body, $clientFactory, $clientProvider)->execute();

        self::assertFalse($this->response['connected']);
        self::assertFalse($this->response['checked']);
        self::assertSame(
            'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
            $this->response['error']
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedBodyProvider(): array
    {
        return [
            'typed credentials' => [['subdomain' => 'engine.example#', 'username' => 'user', 'password' => 'secret']],
            'the saved password' => [['subdomain' => 'engine.example#', 'username' => 'user', 'store_id' => '1']],
        ];
    }

    /**
     * PRO-3628: Test connection with nothing to test asks the merchant to
     * fill in the fields in plain words — a fresh install has no saved
     * credentials, and fields cleared in the form are not the saved ones.
     *
     * @dataProvider nothingToTestProvider
     * @param array<string, mixed> $body
     */
    public function testNothingToTestAsksForTheCredentials(array $body, bool $savedCredentials): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        if ($savedCredentials) {
            $clientProvider->method('forStore')->willReturn($this->createMock(SmailyClient::class));
        } else {
            $clientProvider->method('forStore')->willThrowException(
                new SmailyClientException('Smaily API credentials are not configured (store scope: 1)')
            );
        }
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->expects(self::never())->method('create');

        $this->controller($body, $clientFactory, $clientProvider)->execute();

        self::assertSame(
            ['connected' => false, 'checked' => false, 'error' => 'Please fill in the subdomain, username and password.'],
            $this->response
        );
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function nothingToTestProvider(): array
    {
        $empty = ['subdomain' => '', 'username' => '', 'password' => '', 'store_id' => 1];

        return [
            'all fields empty on a fresh install' => [$empty, false],
            'all fields empty, credentials saved' => [$empty, true],
            'username cleared, credentials saved' => [['subdomain' => 'demo'] + $empty, true],
            'subdomain and username typed, no saved password' => [
                ['subdomain' => 'demo', 'username' => 'user', 'password' => '', 'store_id' => 1],
                false,
            ],
        ];
    }

    /**
     * The saved password is kept when the form shows the saved subdomain and
     * username with the password left blank, and when a per-language account
     * block re-tests its saved account by store view alone.
     *
     * @dataProvider savedPasswordProvider
     * @param array<string, mixed> $body
     */
    public function testABlankPasswordKeepsTheSavedOne(array $body): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::once())->method('validateCredentials');
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::once())->method('forStore')->with(3)->willReturn($client);
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->expects(self::never())->method('create');

        $this->controller($body, $clientFactory, $clientProvider)->execute();

        self::assertTrue($this->response['connected']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function savedPasswordProvider(): array
    {
        return [
            'saved subdomain and username shown' => [
                ['subdomain' => 'demo', 'username' => 'user', 'password' => '', 'store_id' => '3'],
            ],
            'a per-language account block' => [['store_id' => 3]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function controller(
        array $body,
        SmailyClientFactory $clientFactory,
        SmailyClientProvider $clientProvider
    ): TestSmaily {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        return new TestSmaily(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $clientFactory,
            $clientProvider,
            new SubdomainNormalizer()
        );
    }

    private function pressTestConnection(?\Throwable $refusal): void
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn(
            (string)json_encode(['subdomain' => 'demo', 'username' => 'user', 'password' => 'secret'])
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);

        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnCallback(function (array $data) use ($result) {
            $this->response = $data;

            return $result;
        });
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        $client = $this->createMock(SmailyClient::class);
        if ($refusal !== null) {
            $client->method('validateCredentials')->willThrowException($refusal);
        }
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->method('create')->willReturn($client);

        $normalizer = $this->createMock(SubdomainNormalizer::class);
        $normalizer->method('normalize')->willReturnArgument(0);

        (new TestSmaily(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $clientFactory,
            $this->createMock(SmailyClientProvider::class),
            $normalizer
        ))->execute();
    }
}
