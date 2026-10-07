<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Wizard;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Smaily\Connect\Model\Adminhtml\SetupGuard;

/**
 * The guided setup wizard. Also the target of the wizard-first redirect all
 * other Smaily Connect pages perform while setup is incomplete.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::config';

    public function __construct(
        Context $context,
        private readonly SetupGuard $setupGuard
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Page
    {
        $this->setupGuard->checkVersionChange();

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Smaily_Connect::wizard');
        $page->getConfig()->getTitle()->prepend((string)__('Smaily Connect — Initial setup'));

        return $page;
    }
}
