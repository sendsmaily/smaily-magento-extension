<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Automations;

use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Smaily\Connect\Controller\Adminhtml\Api\AbstractJsonAction;
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\EmailTestMode;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\ViewModel\Adminhtml\AutomationsForm;

/**
 * Base of a Campaign Intelligence email card's test mode and test send
 * (CON-458, contract §19, §20): the Intelligence Automations resource that
 * saving these automations needs, a POST with the admin's form key, a
 * connected and active account, and one catalogue email's key — never a
 * store-run or transactional email (§19, §20 answer those 404).
 *
 * Every answer is {ok, message, ...}; an engine call's answer also carries
 * `reasons` (a refusal's reasons in plain words) and `state`, the card's
 * state as Campaign Intelligence reads it after the call (§12).
 */
abstract class AbstractTestModeAction extends AbstractJsonAction
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::automations';

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        protected readonly EmailTestMode $testMode,
        private readonly Settings $settings
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * The refusal to answer before the engine is asked, or null when the
     * request may go on.
     */
    protected function refusal(string $key): ?Json
    {
        $refusal = $this->formKeyRefusal(
            (string)__('Your admin session has expired. Reload the page and try again.')
        );
        if ($refusal !== null) {
            return $refusal;
        }
        if ($this->settings->isRefused()) {
            return $this->answer(503, 'not_available', (string)AutomationsForm::refusedMessage());
        }
        if (!$this->settings->isConnected()) {
            return $this->answer(503, 'not_available', (string)__('Campaign Intelligence is not connected.'));
        }
        if (!EmailDesigns::isEmailKey($key) || !EmailDesigns::isEngineEmail($key)) {
            return $this->answer(
                404,
                'unknown_automation',
                (string)__('Campaign Intelligence does not offer this for this email yet.')
            );
        }

        return null;
    }

    /**
     * The model's answer as the JSON result.
     *
     * @param array{status: int, body: array<string, mixed>} $answer
     */
    protected function result(array $answer): Json
    {
        return $this->jsonResponse($answer['body'], $answer['status']);
    }

    /** A string field of the JSON request body, '' when it is not one. */
    protected function field(string $name): string
    {
        $value = $this->requestBody()[$name] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    private function answer(int $status, string $error, string $message): Json
    {
        return $this->jsonResponse(['ok' => false, 'error' => $error, 'message' => $message], $status);
    }
}
