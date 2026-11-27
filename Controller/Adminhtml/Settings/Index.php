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
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\StoreLayout;

/**
 * Tabbed settings page — the wizard's step content as always-available tabs
 * (Connection / Contacts / Automations / Intelligence / RSS), saving via
 * AJAX into the same system config paths as the wizard and system.xml.
 * One store on one Smaily account (CON-470) shares its settings across its
 * websites: the page reads and saves the values for all websites, as the
 * initial setup does, with no website of its own.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::config';

    public function __construct(
        Context $context,
        private readonly SetupGuard $setupGuard,
        private readonly WebsiteContext $websiteContext,
        private readonly StoreLayout $storeLayout
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
        if ($this->storeLayout->sharesOneAccount()) {
            $this->websiteContext->useStoreWide();
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Smaily_Connect::settings');
        $page->getConfig()->getTitle()->prepend((string)__('Smaily Connect — Settings'));

        return $page;
    }
}
