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
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * POST {setup_url} -> {connected: true, tenantName, engineVersion, catalogImportStarted, customersImportStarted}
 *                   | {connected: false, message}
 *
 * A successful connection starts the catalog import (PRO-3741) and the
 * customers import (PRO-3790); each *ImportStarted is false when that
 * import was already queued or running.
 */
class EngineExchange extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly Client $client,
        private readonly Settings $settings,
        private readonly ImportsOnConnect $importsOnConnect
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $setupUrl = trim((string)($this->requestBody()['setup_url'] ?? ''));
        if ($setupUrl === '') {
            return $this->jsonResponse([
                'connected' => false,
                'message' => (string)__('Paste the setup URL or token from Smaily first.'),
            ]);
        }

        try {
            $response = $this->client->setupExchange($setupUrl);
            $this->settings->storeExchange($response);
            $started = $this->importsOnConnect->start();

            return $this->jsonResponse([
                'connected' => true,
                'tenantName' => (string)($response['tenant_name'] ?? $response['tenant_id'] ?? ''),
                'engineVersion' => (string)($response['engine_version'] ?? ''),
                'catalogImportStarted' => $started[Job::TYPE_CATALOG],
                'customersImportStarted' => $started[Job::TYPE_CUSTOMERS],
            ]);
        } catch (EngineException $exception) {
            // Frame the (possibly technical) engine message in a translated
            // sentence so the failure is understandable in any admin locale.
            return $this->jsonResponse([
                'connected' => false,
                'message' => (string)__('Could not connect to Campaign Intelligence: %1', $exception->getMessage()),
            ]);
        }
    }
}
