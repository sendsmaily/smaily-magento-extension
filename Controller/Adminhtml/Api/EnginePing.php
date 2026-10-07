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
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * POST -> {ok: true, tenantName, engineVersion} | {ok: false, message}
 */
class EnginePing extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly Client $client,
        private readonly Settings $settings
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        if (!$this->settings->isConnected()) {
            return $this->jsonResponse([
                'ok' => false,
                'message' => (string)__('Campaign Intelligence is not connected.'),
            ]);
        }

        try {
            $this->client->ping();

            return $this->jsonResponse([
                'ok' => true,
                'tenantName' => $this->settings->getTenantName() ?: $this->settings->getTenantId(),
                'engineVersion' => $this->settings->getEngineVersion(),
            ]);
        } catch (EngineException $exception) {
            if ($this->settings->isRefused()) {
                // The account, not the connection: say so in the merchant's
                // words rather than quoting a 403 back at them (PRO-2451).
                return $this->jsonResponse([
                    'ok' => false,
                    'message' => (string)__('Campaign Intelligence still reports that this account is not active.'),
                ]);
            }

            // Frame the (possibly technical) engine message in a translated
            // sentence so the failure is understandable in any admin locale.
            return $this->jsonResponse([
                'ok' => false,
                'message' => (string)__('Could not reach Campaign Intelligence: %1', $exception->getMessage()),
            ]);
        }
    }
}
