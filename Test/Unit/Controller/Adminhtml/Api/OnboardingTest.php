<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\DataObject;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Api\DismissBanner;
use Smaily\Connect\Controller\Adminhtml\Api\OnboardingStart;
use Smaily\Connect\Controller\Adminhtml\Api\OnboardingStatus;
use Smaily\Connect\Model\Engine\IntelligenceStart;
use Smaily\Connect\ViewModel\Adminhtml\IntelligenceBannerData;

/**
 * CON-363: the Campaign Intelligence banner's endpoints — the status
 * ("Try Intelligence free" shows), the start and Dismiss. Each is a POST
 * with the admin's form key under the Settings resource, and no answer may
 * be kept by a cache: the start's holds a one-time link.
 */
class OnboardingTest extends TestCase
{
    /** @var array<int|string, mixed> */
    private array $response = [];

    private int $status = 200;

    /** @var array<string, string> */
    private array $headers = [];

    public function testTheEndpointsNeedTheSettingsResource(): void
    {
        self::assertSame('Smaily_Connect::config', OnboardingStatus::ADMIN_RESOURCE);
        self::assertSame('Smaily_Connect::config', OnboardingStart::ADMIN_RESOURCE);
        self::assertSame('Smaily_Connect::config', DismissBanner::ADMIN_RESOURCE);
    }

    public function testTheStatusAnswersWhetherTheStartIsOpen(): void
    {
        $start = $this->createMock(IntelligenceStart::class);
        $start->method('isStartOpen')->willReturn(true);

        $this->statusAction($start)->execute();

        self::assertSame(['start_open' => true], $this->response);
        self::assertSame('no-store, private', $this->headers['Cache-Control'] ?? null);
    }

    public function testTheStartSendsTheAdminsOriginEmailAndLanguage(): void
    {
        $start = $this->createMock(IntelligenceStart::class);
        $start->expects(self::once())->method('start')
            ->with(true, 'https://admin.shop.example', 'owner@shop.example', 'et')
            ->willReturn(['status' => 200, 'body' => [
                'start_url' => 'https://intelligence.smaily.com/onboarding/start#t=a',
                'display' => 'frame',
                'engine_origin' => 'https://intelligence.smaily.com',
            ]]);

        $this->startAction($start, ['display' => 'frame'])->execute();

        self::assertSame(200, $this->status);
        self::assertSame('https://intelligence.smaily.com/onboarding/start#t=a', $this->response['start_url']);
        self::assertSame('no-store, private', $this->headers['Cache-Control'] ?? null);
    }

    public function testAWindowStartAndARefusalKeepTheEnginesStatus(): void
    {
        $start = $this->createMock(IntelligenceStart::class);
        $start->expects(self::once())->method('start')
            ->with(false, self::anything(), self::anything(), self::anything())
            ->willReturn(['status' => 409, 'body' => ['error' => 'store_exists']]);

        $this->startAction($start, ['display' => 'window'])->execute();

        self::assertSame(409, $this->status);
        self::assertSame(['error' => 'store_exists'], $this->response);
        self::assertSame('no-store, private', $this->headers['Cache-Control'] ?? null);
    }

    public function testAnInvalidFormKeyIsRefusedBeforeAnythingIsAsked(): void
    {
        $start = $this->createMock(IntelligenceStart::class);
        $start->expects(self::never())->method('start');
        $start->expects(self::never())->method('isStartOpen');

        $this->startAction($start, ['display' => 'frame'], formKeyValid: false)->execute();
        self::assertSame(403, $this->status);
        self::assertSame(['error' => 'invalid_form_key'], $this->response);

        $this->statusAction($start, formKeyValid: false)->execute();
        self::assertSame(403, $this->status);

        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('save');
        $this->dismissAction($writer, formKeyValid: false)->execute();
        self::assertSame(403, $this->status);
    }

    public function testDismissKeepsTheTimeAtTheDefaultScopeAndCleansTheConfigCache(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::once())->method('save')
            ->with(IntelligenceBannerData::XML_PATH_DISMISSED_AT, '1763640000');
        $cacheTypes = $this->createMock(TypeListInterface::class);
        $cacheTypes->expects(self::once())->method('cleanType')->with('config');

        $this->dismissAction($writer, $cacheTypes)->execute();

        self::assertSame(['dismissed' => true], $this->response);
    }

    private function statusAction(IntelligenceStart $start, bool $formKeyValid = true): OnboardingStatus
    {
        return new OnboardingStatus(
            $this->context([]),
            $this->jsonFactory(),
            new JsonSerializer(),
            $this->formKeyValidator($formKeyValid),
            $start
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function startAction(IntelligenceStart $start, array $body, bool $formKeyValid = true): OnboardingStart
    {
        $locale = $this->createMock(ResolverInterface::class);
        $locale->method('getLocale')->willReturn('et_EE');

        return new OnboardingStart(
            $this->context($body),
            $this->jsonFactory(),
            new JsonSerializer(),
            $this->formKeyValidator($formKeyValid),
            $start,
            $locale
        );
    }

    private function dismissAction(
        WriterInterface $writer,
        ?TypeListInterface $cacheTypes = null,
        bool $formKeyValid = true
    ): DismissBanner {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_763_640_000);

        return new DismissBanner(
            $this->context([]),
            $this->jsonFactory(),
            new JsonSerializer(),
            $this->formKeyValidator($formKeyValid),
            $writer,
            $cacheTypes ?? $this->createMock(TypeListInterface::class),
            $dateTime
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function context(array $body): Context
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode($body));
        $request->method('getScheme')->willReturn('https');
        $request->method('getHttpHost')->willReturn('admin.shop.example');
        $auth = $this->createMock(Auth::class);
        $auth->method('getUser')->willReturn(new DataObject(['email' => ' owner@shop.example ']));
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getAuth')->willReturn($auth);

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
