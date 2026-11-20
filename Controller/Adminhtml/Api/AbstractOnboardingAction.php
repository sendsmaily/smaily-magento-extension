<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;

/**
 * Base of the Campaign Intelligence banner's endpoints (CON-363): the
 * Settings ACL resource (Smaily_Connect::config, from AbstractJsonAction),
 * a POST with the admin's form key — checked here as well as by Magento's
 * backend validator, as the "Edit design" endpoints do — and answers no
 * cache may keep (`Cache-Control: no-store, private`), because the start's
 * answer holds a one-time link.
 */
abstract class AbstractOnboardingAction extends AbstractJsonAction
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly FormKeyValidator $formKeyValidator
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /** The answer to a request without a valid form key, or null when it may go on. */
    protected function formKeyRefusal(): ?Json
    {
        if ($this->formKeyValidator->validate($this->getRequest())) {
            return null;
        }

        return $this->answer(['error' => 'invalid_form_key'], 403);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function answer(array $data, int $httpStatus = 200): Json
    {
        $result = $this->jsonResponse($data, $httpStatus);
        $result->setHeader('Cache-Control', 'no-store, private', true);

        return $result;
    }
}
