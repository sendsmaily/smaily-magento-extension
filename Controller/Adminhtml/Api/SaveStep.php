<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Client\VerifiedCredentials;

/**
 * POST {step: connect|subscribers|automations|intelligence|finish, data: {...}}
 * -> {saved: bool, errors: [{field, message}], verified?: bool}
 *
 * The finish step also answers whether Smaily accepted the saved
 * credentials at the last check (PRO-3560), for the Overview step's copy.
 *
 * Persists one wizard step. All writes go to the same system config paths
 * the Stores > Configuration page edits — the wizard is an onboarding view
 * over the exact same single source of truth.
 */
class SaveStep extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly WizardStepSaver $stepSaver,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly WebsiteContext $websiteContext
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $step = (string)($body['step'] ?? '');
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        $errors = $this->stepSaver->save($step, $data);

        $response = [
            'saved' => $errors === [],
            'errors' => $errors,
        ];
        if ($step === 'finish') {
            $response['verified'] = $this->verifiedCredentials->isVerified($this->websiteContext->getStoreId());
        }

        return $this->jsonResponse($response);
    }
}
