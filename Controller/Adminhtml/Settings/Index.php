<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Settings;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Smaily\Connect\Model\Adminhtml\SetupGuard;

/**
 * Tabbed settings page — the wizard's step content as always-available tabs
 * (Connection / Contacts / Automations / Intelligence / RSS), saving via
 * AJAX into the same system config paths as the wizard and system.xml.
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
        $page->setActiveMenu('Smaily_Connect::settings');
        $page->getConfig()->getTitle()->prepend((string)__('Smaily Connect — Settings'));

        return $page;
    }
}
