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
use Smaily\Connect\Controller\Adminhtml\Api\Workflows;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * PRO-3575: the workflow list is never fetched from a host that a posted
 * subdomain would make up.
 */
class WorkflowsTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $response = [];

    /**
     * @dataProvider refusedBodyProvider
     * @param array<string, mixed> $body
     */
    public function testASubdomainThatIsNotPlainIsRefusedWithoutARequest(array $body): void
    {
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->expects(self::never())->method('create');
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->expects(self::never())->method('forStore');

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

        (new Workflows(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $clientFactory,
            $clientProvider,
            new SubdomainNormalizer()
        ))->execute();

        self::assertSame([], $this->response['workflows']);
        self::assertSame(
            'Could not load the workflow list: The subdomain must be a plain Smaily subdomain such as "demo":'
            . ' letters, digits and hyphens only.',
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
            'the saved password' => [['subdomain' => 'engine.example#', 'store_id' => '1']],
        ];
    }
}
