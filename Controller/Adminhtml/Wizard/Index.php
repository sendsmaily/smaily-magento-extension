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
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;

/**
 * The guided setup wizard. Also the target of the wizard-first redirect all
 * other Smaily Connect pages perform while setup is incomplete. It sets the
 * whole store up (CON-179): the page reads and saves the store-wide values.
 * On a store set up as separate stores (CON-279) it sets up the website the
 * request names (`?website=<id>`, else the default website), as a single
 * store's setup does.
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
     */
    public function execute(): Page
    {
        $this->setupGuard->checkVersionChange();
        if (!$this->storeLayout->isSeparateStores()) {
            $this->websiteContext->useStoreWide();
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->setActiveMenu('Smaily_Connect::wizard');
        $page->getConfig()->getTitle()->prepend((string)__('Smaily Connect — Initial setup'));

        return $page;
    }
}
