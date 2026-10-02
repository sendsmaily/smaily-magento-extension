<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Privacy;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Customer account page for the shopper personalization (profiling) choice.
 *
 * Exists only where Campaign Intelligence is live — connected and the
 * account not refused (PRO-3579, Woo PRO-2513/PRO-3189): elsewhere the page's
 * claim that shopping activity personalizes recommendations is untrue, so a
 * direct request is a 404 like any page the store does not have.
 */
class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ResultFactory $resultFactory,
        private readonly Settings $engineSettings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Page|Redirect|Forward
    {
        if (!$this->engineSettings->isSendingAllowed()) {
            /** @var Forward $forward */
            $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);

            return $forward->forward('noroute');
        }
        if (!$this->customerSession->isLoggedIn()) {
            /** @var Redirect $redirect */
            $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

            return $redirect->setPath('customer/account/login');
        }

        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set((string)__('Personalization'));

        return $page;
    }
}
