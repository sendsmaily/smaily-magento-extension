<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Privacy;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Forward;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Message\ManagerInterface;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * Persists the shopper's personalization (profiling) choice. Behind the same
 * gate as the page (PRO-3579): without live Campaign Intelligence it is a 404
 * and changes nothing.
 */
class Save implements HttpPostActionInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly ProfilingConsent $profilingConsent,
        private readonly ManagerInterface $messageManager,
        private readonly Settings $engineSettings
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect|Forward
    {
        if (!$this->engineSettings->isSendingAllowed()) {
            /** @var Forward $forward */
            $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);

            return $forward->forward('noroute');
        }

        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        if (!$this->customerSession->isLoggedIn()) {
            return $redirect->setPath('customer/account/login');
        }
        if (!$this->formKeyValidator->validate($this->request)) {
            return $redirect->setPath('smaily/privacy');
        }

        $allowed = (bool)$this->request->getParam('profiling_allowed');
        $customer = $this->customerSession->getCustomerData();
        $this->profilingConsent->setAllowed(
            (string)$customer->getEmail(),
            $allowed,
            $customer->getStoreId()
        );

        $this->messageManager->addSuccessMessage(
            (string)__('Your personalization preference has been saved.')
        );

        return $redirect->setPath('smaily/privacy');
    }
}
