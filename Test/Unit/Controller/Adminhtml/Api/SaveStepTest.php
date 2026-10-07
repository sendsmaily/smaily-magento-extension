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
use Smaily\Connect\Controller\Adminhtml\Api\SaveStep;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * PRO-3628: the answer to the initial setup's finish step says whether Smaily
 * accepted the saved credentials (PRO-3560), so the Overview step says
 * "syncing" only for a store that is connected.
 */
class SaveStepTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $response = [];

    /**
     * @dataProvider verifiedProvider
     */
    public function testFinishAnswersWhetherTheConnectionIsVerified(bool $verified): void
    {
        $verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $verifiedCredentials->method('isVerified')->willReturn(!$verified);
        $verifiedCredentials->method('isWebsiteVerified')->with(3)->willReturn($verified);

        $this->controller(['step' => 'finish', 'data' => []], $verifiedCredentials)->execute();

        self::assertSame(['saved' => true, 'errors' => [], 'verified' => $verified], $this->response);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function verifiedProvider(): array
    {
        return ['connected' => [true], 'not connected' => [false]];
    }

    /**
     * PRO-3570: a connection save answers the result of the check it made,
     * so the Connection status changes without a reload.
     *
     * @dataProvider verifiedProvider
     */
    public function testAConnectionSaveAnswersTheResultOfItsCheck(bool $accepted): void
    {
        $verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $verifiedCredentials->expects(self::never())->method('isWebsiteVerified');
        $body = ['step' => 'connect', 'data' => ['subdomain' => 'https://Demo.sendsmaily.net/', 'username' => 'u']];

        $this->controller($body, $verifiedCredentials, [], $accepted)->execute();

        self::assertSame(
            [
                'saved' => true,
                'errors' => [],
                'verified' => $accepted,
                'accountName' => 'demo',
                'storefrontUrlChanged' => false,
            ],
            $this->response
        );
    }

    /**
     * PRO-3660: a connection save that changed the storefront address says
     * so, for the result to ask for the catalog import again.
     */
    public function testAConnectionSaveAnswersWhetherTheStorefrontUrlChanged(): void
    {
        $body = ['step' => 'connect', 'data' => ['subdomain' => 'demo', 'username' => 'u']];

        $this->controller($body, $this->createMock(VerifiedCredentials::class), [], true, true)->execute();

        self::assertTrue($this->response['storefrontUrlChanged']);
    }

    public function testARefusedConnectionSaveAnswersOnlyTheErrors(): void
    {
        $errors = [['field' => 'subdomain', 'message' => 'Subdomain and username are required.']];

        $this->controller(
            ['step' => 'connect', 'data' => []],
            $this->createMock(VerifiedCredentials::class),
            $errors
        )->execute();

        self::assertSame(['saved' => false, 'errors' => $errors], $this->response);
    }

    public function testOtherStepsAnswerOnlyTheSave(): void
    {
        $verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $verifiedCredentials->expects(self::never())->method('isWebsiteVerified');

        $this->controller(['step' => 'rss', 'data' => ['rss_enabled' => true]], $verifiedCredentials)->execute();

        self::assertSame(['saved' => true, 'errors' => []], $this->response);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<int, array{field: string, message: string}> $errors
     */
    private function controller(
        array $body,
        VerifiedCredentials $verifiedCredentials,
        array $errors = [],
        bool $connectionAccepted = false,
        bool $storefrontUrlChanged = false
    ): SaveStep {
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

        $stepSaver = $this->createMock(WizardStepSaver::class);
        $stepSaver->method('save')->willReturn($errors);
        $stepSaver->method('isConnectionAccepted')->willReturn($connectionAccepted);
        $stepSaver->method('isStorefrontUrlChanged')->willReturn($storefrontUrlChanged);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('getWebsiteId')->willReturn(3);

        return new SaveStep(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $stepSaver,
            $verifiedCredentials,
            $websiteContext,
            new SubdomainNormalizer()
        );
    }
}
