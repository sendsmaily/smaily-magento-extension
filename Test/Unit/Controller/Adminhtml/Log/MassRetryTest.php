<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml\Log;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProviderInterface;
use Magento\Framework\View\Element\UiComponentInterface;
use Magento\Ui\Component\MassAction\Filter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Log\MassRetry;
use Smaily\Connect\Model\Log\SelectionRetry;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * PRO-2510: the mass Retry hands the grid's selection on as its unloaded
 * query, instead of Filter::getCollection()'s list of every selected id.
 */
class MassRetryTest extends TestCase
{
    private Filter&MockObject $filter;
    private SelectionRetry&MockObject $selectionRetry;
    private ManagerInterface&MockObject $messages;
    private Collection $selection;

    protected function setUp(): void
    {
        $this->selection = $this->createMock(Collection::class);
        $dataProvider = $this->createMock(DataProviderInterface::class);
        $dataProvider->method('getSearchResult')->willReturn($this->selection);
        $uiContext = $this->createMock(ContextInterface::class);
        $uiContext->method('getDataProvider')->willReturn($dataProvider);
        $component = $this->createMock(UiComponentInterface::class);
        $component->method('getContext')->willReturn($uiContext);

        $this->filter = $this->createMock(Filter::class);
        $this->filter->method('getComponent')->willReturn($component);
        $this->selectionRetry = $this->createMock(SelectionRetry::class);
        $this->messages = $this->createMock(ManagerInterface::class);
    }

    public function testSelectAllRetriesTheGridsSelectionAndSaysWhatWasSkipped(): void
    {
        $this->filter->expects(self::once())->method('applySelectionOnTargetProvider');
        $this->filter->expects(self::never())->method('getCollection');
        $this->selectionRetry->expects(self::once())->method('retry')
            ->with(self::identicalTo($this->selection))
            ->willReturn([20500, 2]);
        $this->messages->expects(self::once())->method('addSuccessMessage')
            ->with('20500 event(s) queued for retry, 2 skipped because sending again would not be safe.');

        $this->controller(['excluded' => 'false'])->execute();
    }

    public function testNothingSelectedIsRefusedAsBefore(): void
    {
        $this->selectionRetry->expects(self::never())->method('retry');

        $this->expectException(LocalizedException::class);
        $this->controller([])->execute();
    }

    /**
     * @param array<string, mixed> $params
     */
    private function controller(array $params): MassRetry
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $params[$name] ?? null);
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnSelf();
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getMessageManager')->willReturn($this->messages);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return new MassRetry($context, $this->filter, $this->selectionRetry);
    }
}
