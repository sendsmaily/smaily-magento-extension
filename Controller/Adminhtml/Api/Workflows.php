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
use Smaily\Connect\Model\Client\Exception\InvalidSubdomainException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\SmailyUrl;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * POST {subdomain?, username?, password?, store_id?}
 * -> {workflows: [{id, name}], error?}
 *
 * When credentials are posted (config page / wizard with unsaved input) the
 * list is fetched with those; otherwise the saved credentials for the given
 * store scope are used. This is what lets the workflow dropdowns fill in
 * WITHOUT saving the configuration first.
 */
class Workflows extends AbstractJsonAction implements HttpPostActionInterface
{
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        JsonSerializer $serializer,
        private readonly SmailyClientFactory $clientFactory,
        private readonly SmailyClientProvider $clientProvider,
        private readonly SubdomainNormalizer $normalizer
    ) {
        parent::__construct($context, $jsonFactory, $serializer);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $body = $this->requestBody();
        $subdomain = $this->normalizer->normalize((string)($body['subdomain'] ?? ''));
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');

        try {
            if ($subdomain !== '' && !SmailyUrl::isPlainSubdomain($subdomain)) {
                throw new InvalidSubdomainException();
            }
            if ($subdomain !== '' && $username !== '' && $password !== '') {
                $client = $this->clientFactory->create([
                    'subdomain' => $subdomain,
                    'username' => $username,
                    'password' => $password,
                ]);
            } else {
                $storeId = isset($body['store_id']) ? (int)$body['store_id'] : null;
                $client = $this->clientProvider->forStore($storeId ?: null);
            }

            $workflows = [];
            foreach ($client->getAutomationWorkflows() as $workflow) {
                $workflows[] = ['id' => (string)$workflow['id'], 'name' => $workflow['title']];
            }

            return $this->jsonResponse(['workflows' => $workflows]);
        } catch (SmailyClientException $exception) {
            // Frame the client message in a translated sentence so the
            // failure is understandable in any admin locale.
            return $this->jsonResponse([
                'workflows' => [],
                'error' => (string)__('Could not load the workflow list: %1', $exception->getMessage()),
            ]);
        }
    }
}
