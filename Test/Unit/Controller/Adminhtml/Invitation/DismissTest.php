<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Invitation;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Invitation\Dismiss;
use Smaily\Connect\ViewModel\Adminhtml\SmailyInvitationData;

/**
 * CON-364: Dismiss on the Smaily invitation (the system message's link and
 * the Dashboard banner's) — a GET link for admins who may open the
 * Settings, as Magento's "Mark as Read"; with a valid form key it keeps the
 * time at the default scope and cleans the config cache, and it always
 * returns to the page it came from.
 */
class DismissTest extends TestCase
{
    public function testALinkForTheSettingsResource(): void
    {
        self::assertSame('Smaily_Connect::config', Dismiss::ADMIN_RESOURCE);
        self::assertContains(HttpGetActionInterface::class, class_implements(Dismiss::class));
    }

    public function testDismissKeepsTheTimeAndReturnsToThePage(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::once())->method('save')
            ->with(SmailyInvitationData::XML_PATH_DISMISSED_AT, '1763640000');
        $cacheTypes = $this->createMock(TypeListInterface::class);
        $cacheTypes->expects(self::once())->method('cleanType')->with('config');
        $redirect = $this->redirect();

        self::assertSame($redirect, $this->action($writer, $cacheTypes, true, $redirect)->execute());
    }

    public function testAnInvalidFormKeyDismissesNothing(): void
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->expects(self::never())->method('save');
        $cacheTypes = $this->createMock(TypeListInterface::class);
        $cacheTypes->expects(self::never())->method('cleanType');
        $redirect = $this->redirect();

        self::assertSame($redirect, $this->action($writer, $cacheTypes, false, $redirect)->execute());
    }

    private function redirect(): Redirect
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setRefererOrBaseUrl')->willReturnSelf();

        return $redirect;
    }

    private function action(
        WriterInterface $writer,
        TypeListInterface $cacheTypes,
        bool $formKeyValid,
        Redirect $redirect
    ): Dismiss {
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_REDIRECT)->willReturn($redirect);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->createMock(HttpRequest::class));
        $context->method('getResultFactory')->willReturn($resultFactory);
        $validator = $this->createMock(FormKeyValidator::class);
        $validator->method('validate')->willReturn($formKeyValid);
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(1_763_640_000);

        return new Dismiss($context, $validator, $writer, $cacheTypes, $dateTime);
    }
}
