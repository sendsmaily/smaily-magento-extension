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
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Model\Engine\IntelligenceStart;

/**
 * POST (form key) -> {start_open}
 *
 * Whether the Campaign Intelligence banner shows "Try Intelligence free"
 * (contract §21 "Is the start open?", CON-363). The banner asks after the
 * page has loaded, so a page load never waits on the engine; the engine's
 * answer is kept five minutes (IntelligenceStart).
 */
class OnboardingStatus extends AbstractOnboardingAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        FormKeyValidator $formKeyValidator,
        private readonly IntelligenceStart $intelligenceStart
    ) {
        parent::__construct($context, $jsonFactory, $serializer, $formKeyValidator);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        return $this->formKeyRefusal()
            ?? $this->answer(['start_open' => $this->intelligenceStart->isStartOpen()]);
    }
}
