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
use Smaily\Connect\Model\Engine\EmailDesigns;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\ViewModel\Adminhtml\AutomationsForm;

/**
 * Base of the "Edit design" endpoints (CON-257, contract §22, §23): the
 * Settings ACL resource (Smaily_Connect::config, from AbstractJsonAction),
 * a POST with the admin's form key, and the engine's answers in the
 * admin's words. The engine's API key stays on the server.
 *
 * A failure answers {error, message} with an HTTP status — never the
 * engine's own body, a link or the API key.
 */
abstract class AbstractEmailDesignAction extends AbstractJsonAction
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        protected readonly EmailDesigns $emailDesigns,
        private readonly FormKeyValidator $formKeyValidator
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * The form key and the connection, checked before the engine is asked:
     * the refusal to answer with, or null when the request may go on.
     */
    protected function refusal(): ?Json
    {
        // Magento's backend request validator checks the form key of every
        // POST too; it is checked here as well, so these endpoints never rest
        // on a framework setting alone.
        if (!$this->formKeyValidator->validate($this->getRequest())) {
            return $this->failure(
                403,
                'invalid_form_key',
                (string)__('Your admin session has expired. Reload the page and try again.')
            );
        }
        if (!$this->emailDesigns->isAvailable()) {
            return $this->failure(503, 'not_available', (string)__('Campaign Intelligence is not connected.'));
        }

        return null;
    }

    /**
     * An engine failure in the admin's words (as the WooCommerce plugin's
     * EmailDesignsEndpoint maps them).
     */
    protected function engineFailure(EngineException $exception): Json
    {
        $status = (int)$exception->getCode();
        $error = $exception instanceof EngineRequestException
            ? (string)($exception->getErrorBody()['error'] ?? '')
            : '';

        if ($status === 404 && $error === 'unknown_email') {
            return $this->failure(
                404,
                'unknown_email',
                (string)__('Campaign Intelligence offers no design for this email.')
            );
        }
        if ($status === 404) {
            // A route the engine does not offer yet (§1).
            return $this->failure(
                404,
                'not_available',
                (string)__('Campaign Intelligence does not offer email designs for this store yet.')
            );
        }
        if ($status === 401) {
            return $this->failure(
                502,
                'api_key_rejected',
                (string)__(
                    'Campaign Intelligence did not accept this store\'s connection.'
                    . ' Connect again under Settings > Intelligence.'
                )
            );
        }
        if ($status === 403) {
            return $this->failure(502, 'tenant_inactive', (string)AutomationsForm::refusedMessage());
        }
        if ($status === 429) {
            return $this->failure(
                429,
                'rate_limit_exceeded',
                (string)__('Too many design pages were opened in a short time. Try again in a minute.')
            );
        }

        return $this->failure(
            502,
            $error === 'invalid_engine_response' ? $error : 'engine_unavailable',
            (string)__('The design page could not be opened. Try again in a minute.')
        );
    }

    protected function failure(int $status, string $error, string $message): Json
    {
        return $this->noStore($this->jsonResponse(['error' => $error, 'message' => $message], $status));
    }

    /** No cache may keep an answer of these endpoints. */
    protected function noStore(Json $result): Json
    {
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result;
    }
}
