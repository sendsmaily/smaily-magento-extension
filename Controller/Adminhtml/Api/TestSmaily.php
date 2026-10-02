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
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * POST /test-smaily {subdomain, username, password} or {store_id}
 * -> {connected: bool, accountName?: string, error?: string}
 *
 * With credentials posted, those are tested as typed. Without a password, a
 * store_id falls back to the credentials SAVED for that store view — this is
 * what lets a per-language account block (multilingual mode A) re-test a
 * saved account without retyping its password.
 */
class TestSmaily extends AbstractJsonAction implements HttpPostActionInterface
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
        $storeId = isset($body['store_id']) && $body['store_id'] !== '' ? (int)$body['store_id'] : null;

        if ($password === '' && $storeId !== null) {
            try {
                $client = $this->clientProvider->forStore($storeId);
                $subdomain = $subdomain !== '' ? $subdomain : 'saved';
            } catch (SmailyClientException $exception) {
                return $this->jsonResponse(['connected' => false, 'error' => $exception->getMessage()]);
            }
        } elseif ($subdomain === '' || $username === '' || $password === '') {
            return $this->jsonResponse([
                'connected' => false,
                'error' => (string)__('Please fill in the subdomain, username and password.'),
            ]);
        } else {
            $client = $this->clientFactory->create([
                'subdomain' => $subdomain,
                'username' => $username,
                'password' => $password,
            ]);
        }

        try {
            $client->validateCredentials();

            return $this->jsonResponse(['connected' => true, 'accountName' => $subdomain]);
        } catch (PlanBlockedException $exception) {
            // The package, not the credentials (PRO-3579).
            return $this->jsonResponse(['connected' => false, 'error' => $exception->getMessage()]);
        } catch (AuthenticationException) {
            return $this->jsonResponse([
                'connected' => false,
                'error' => (string)__('Smaily rejected these credentials. Check the subdomain, username and password.'),
            ]);
        } catch (SmailyClientException $exception) {
            return $this->jsonResponse([
                'connected' => false,
                'error' => (string)__('Could not reach Smaily: %1', $exception->getMessage()),
            ]);
        }
    }
}
