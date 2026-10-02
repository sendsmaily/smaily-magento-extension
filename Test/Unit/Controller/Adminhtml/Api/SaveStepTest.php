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
        $verifiedCredentials->method('isVerified')->with(4)->willReturn($verified);

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

    public function testOtherStepsAnswerOnlyTheSave(): void
    {
        $verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $verifiedCredentials->expects(self::never())->method('isVerified');

        $this->controller(['step' => 'rss', 'data' => ['rss_enabled' => true]], $verifiedCredentials)->execute();

        self::assertSame(['saved' => true, 'errors' => []], $this->response);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function controller(array $body, VerifiedCredentials $verifiedCredentials): SaveStep
    {
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
        $stepSaver->method('save')->willReturn([]);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('getStoreId')->willReturn(4);

        return new SaveStep(
            $context,
            $jsonFactory,
            new JsonSerializer(),
            $stepSaver,
            $verifiedCredentials,
            $websiteContext
        );
    }
}
