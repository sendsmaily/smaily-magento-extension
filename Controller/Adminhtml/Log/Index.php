<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Smaily\Connect\Model\Adminhtml\SetupGuard;

/**
 * Unified delivery log: Smaily (marketing events) and Campaign Intelligence
 * (ingest) rows in one grid, told apart by the Source column.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly SetupGuard $setupGuard
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     *
     * @return Page|Redirect
     */
    public function execute(): ResultInterface
    {
        $redirect = $this->setupGuard->redirectIfNotSetUp($this->resultFactory);
        if ($redirect !== null) {
            return $redirect;
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Smaily_Connect::log');
        $page->getConfig()->getTitle()->prepend((string)__('Smaily Connect — Log'));

        return $page;
    }
}
