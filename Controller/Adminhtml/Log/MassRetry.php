<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\MassAction\Filter;
use Smaily\Connect\Model\Log\SelectionRetry;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Resets selected failed rows back to pending — the composite log id routes
 * each row to its own queue (Smaily events / Intelligence ingest). A row
 * ResendGuard refuses is left alone and counted (PRO-2454): the merchant is
 * told how many were skipped rather than quietly getting a smaller number.
 *
 * The selection reaches SelectionRetry as the grid's own query (PRO-2510):
 * Filter::getCollection() would first load every row of a "Select all"
 * and filter the grid again by the whole list of their ids.
 */
class MassRetry extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly Filter $filter,
        private readonly SelectionRetry $selectionRetry
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        [$retried, $skipped] = $this->selectionRetry->retry($this->selection());

        $this->messageManager->addSuccessMessage(
            $skipped > 0
                ? (string)__(
                    '%1 event(s) queued for retry, %2 skipped because sending again would not be safe.',
                    $retried,
                    $skipped
                )
                : (string)__('%1 event(s) queued for retry.', $retried)
        );

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('smaily_connect/log');
    }

    /**
     * The grid's rows as the request selects them — its filters, and the
     * ticked rows or "Select all" less the unticked ones — still unloaded.
     * Refuses an empty selection as Filter::getCollection() does.
     */
    private function selection(): Collection
    {
        $selected = $this->getRequest()->getParam(Filter::SELECTED_PARAM);
        $excluded = $this->getRequest()->getParam(Filter::EXCLUDED_PARAM);
        if ($excluded !== 'false' && !(is_array($excluded) && $excluded) && !(is_array($selected) && $selected)) {
            throw new LocalizedException(__('An item needs to be selected. Select and try again.'));
        }

        $this->filter->applySelectionOnTargetProvider();
        $selection = $this->filter->getComponent()->getContext()->getDataProvider()->getSearchResult();
        if (!$selection instanceof Collection) {
            throw new \UnexpectedValueException('The Log grid is not backed by the Log collection.');
        }

        return $selection;
    }
}
