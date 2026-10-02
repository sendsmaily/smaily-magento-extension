<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Cart;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Smaily\Connect\Model\AbandonedCart\RestoreTokenManager;

/**
 * Abandoned cart recovery link target (GET smaily/cart/restore?id=&ts=&token=):
 * restores the shopper's still-active quote into the checkout session so the
 * {{abandoned_cart_url}} in the reminder email brings back the exact cart.
 * An expired link lands on the cart page with a notice and restores nothing.
 */
class Restore implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ResultFactory $resultFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly CheckoutSession $checkoutSession,
        private readonly CustomerSession $customerSession,
        private readonly RestoreTokenManager $tokenManager,
        private readonly ManagerInterface $messageManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $redirect->setPath('checkout/cart');

        $quoteId = (int)$this->request->getParam('id');
        $verdict = $this->tokenManager->check(
            $quoteId,
            (string)$this->request->getParam('ts'),
            (string)$this->request->getParam('token')
        );
        if ($verdict === RestoreTokenManager::EXPIRED) {
            $this->messageManager->addNoticeMessage((string)__('This cart link has expired.'));

            return $redirect;
        }
        if ($verdict !== RestoreTokenManager::VALID) {
            return $redirect;
        }

        try {
            $quote = $this->cartRepository->get($quoteId);
        } catch (NoSuchEntityException) {
            $this->messageManager->addNoticeMessage((string)__('That cart is no longer available.'));

            return $redirect;
        }

        if (!$quote instanceof Quote || !$quote->getIsActive()) {
            $this->messageManager->addNoticeMessage((string)__('That cart is no longer available.'));

            return $redirect;
        }

        $quoteCustomerId = (int)$quote->getCustomerId();
        if ($quoteCustomerId > 0 && $quoteCustomerId !== (int)$this->customerSession->getCustomerId()) {
            // A customer's cart is restored by logging in, not by the link.
            $this->messageManager->addNoticeMessage(
                (string)__('Please sign in to see your saved cart.')
            );

            return $redirect->setPath('customer/account/login');
        }

        $this->checkoutSession->replaceQuote($quote);

        return $redirect;
    }
}
