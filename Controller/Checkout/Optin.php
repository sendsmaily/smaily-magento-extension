<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Smaily\Connect\Model\Config;

/**
 * Persists the checkout newsletter opt-in choice for the current quote
 * (POST smaily/checkout/optin, called by the checkout checkbox component).
 *
 * CSRF: the component sends the form key inside a JSON body, which the
 * standard framework validator cannot see — so the controller implements
 * CsrfAwareActionInterface and validates the body form key itself.
 */
class Optin implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly StateManager $stateManager,
        private readonly JsonSerializer $serializer,
        private readonly FormKey $formKey,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        $body = $this->decodeBody();
        $websiteId = (int)$this->storeManager->getStore()->getWebsiteId();
        if (!$this->isFormKeyValid($body) || !$this->config->isCheckoutOptinEnabled($websiteId)) {
            $result->setHttpResponseCode(400);

            return $result->setData(['success' => false]);
        }

        $quote = $this->checkoutSession->getQuote();
        $quoteId = (int)$quote->getId();
        if ($quoteId > 0) {
            $this->stateManager->setNewsletterOptin(
                $quoteId,
                (int)$quote->getStoreId(),
                $quote->getCustomerEmail() ? (string)$quote->getCustomerEmail() : null,
                (bool)($body['opted_in'] ?? false)
            );
        }

        return $result->setData(['success' => $quoteId > 0]);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return $this->isFormKeyValid($this->decodeBody());
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(): array
    {
        $rawContent = (string)$this->request->getContent();
        if ($rawContent === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($rawContent);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function isFormKeyValid(array $body): bool
    {
        $submitted = $body['form_key'] ?? '';
        if (!is_string($submitted)) {
            return false;
        }

        try {
            return $submitted !== '' && hash_equals($this->formKey->getFormKey(), $submitted);
        } catch (\Exception) {
            return false;
        }
    }
}
