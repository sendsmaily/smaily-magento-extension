<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

/**
 * Base for the admin SPA's JSON endpoints. The response contracts mirror the
 * WooCommerce plugin's REST namespace one-to-one so the ported React app
 * runs against Magento without translation layers on the client.
 */
abstract class AbstractJsonAction extends Action
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::config';

    /** The Cache-Control of an answer no cache may keep (noStore()). */
    protected const NO_STORE = 'no-store';

    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly JsonSerializer $serializer
    ) {
        parent::__construct($context);
    }

    /**
     * @param array<int|string, mixed> $data
     */
    protected function jsonResponse(array $data, int $httpStatus = 200): Json
    {
        $result = $this->jsonFactory->create();
        if ($httpStatus !== 200) {
            $result->setHttpResponseCode($httpStatus);
        }

        return $result->setData($data);
    }

    /** No cache may keep this answer: `Cache-Control` is static::NO_STORE. */
    protected function noStore(Json $result): Json
    {
        $result->setHeader('Cache-Control', static::NO_STORE, true);

        return $result;
    }

    /**
     * The answer to a request without a valid form key — 403
     * {error: invalid_form_key[, message]}, kept by no cache — or null when
     * it may go on. Magento's backend request validator checks the form key
     * of every POST too; it is checked here as well, so an endpoint that
     * asks for it never rests on a framework setting alone.
     */
    protected function formKeyRefusal(?string $message = null): ?Json
    {
        if ($this->_formKeyValidator->validate($this->getRequest())) {
            return null;
        }
        $answer = ['error' => 'invalid_form_key'];
        if ($message !== null) {
            $answer['message'] = $message;
        }

        return $this->noStore($this->jsonResponse($answer, 403));
    }

    /**
     * Decode the JSON request body.
     *
     * @return array<string, mixed>
     */
    protected function requestBody(): array
    {
        $request = $this->getRequest();
        $content = $request instanceof HttpRequest ? (string)$request->getContent() : '';
        if ($content === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($content);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
