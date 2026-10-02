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

    private function pressTestConnection(\Throwable $refusal): void
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
        $client->method('validateCredentials')->willThrowException($refusal);
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
