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
