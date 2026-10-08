<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Relay;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Smaily\Connect\Model\Engine\BrowseEventValidator;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\RateLimit\PerAddressLimiter;

/**
 * Public browse-beacon proxy (POST smaily/relay): the storefront tracker
 * posts event batches here; the controller forwards them to the engine so
 * the API key never reaches the browser (contract: auth). Best-effort and
 * loss-tolerant — browse events are never queued (matches Woo relay), and
 * the forward is one short attempt (Client::relayBrowse()), so the engine
 * never holds a storefront request.
 *
 * CSRF-exempt: an anonymous beacon has no session form key; the payload is
 * strictly validated and the endpoint 404s when browse tracking is off.
 */
class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const MAX_EVENTS = 100;
    private const RATE_LIMIT_PER_MINUTE = 30;

    public function __construct(
        private readonly HttpRequest $request,
        private readonly JsonFactory $jsonFactory,
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly BrowseEventValidator $validator,
        private readonly PerAddressLimiter $limiter,
        private readonly Logger $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $result = $this->jsonFactory->create();

        // A refused account (contract §2) receives nothing, so the beacon is
        // answered exactly like a store with tracking switched off.
        if (!$this->settings->isBrowseTrackingEnabled() || !$this->settings->isSendingAllowed()) {
            $result->setHttpResponseCode(404);

            return $result->setData(['ok' => false]);
        }

        // Cheap per-address limit: the store must not be usable as an
        // authenticated amplifier against the engine.
        if (!$this->limiter->allow('smaily_relay_', self::RATE_LIMIT_PER_MINUTE)) {
            $result->setHttpResponseCode(429);

            return $result->setData(['ok' => false, 'error' => 'rate limited']);
        }

        $decoded = json_decode((string)$this->request->getContent(), true);
        $rawEvents = is_array($decoded) ? ($decoded['events'] ?? null) : null;
        if (!is_array($rawEvents) || $rawEvents === []) {
            $result->setHttpResponseCode(400);

            return $result->setData(['ok' => false, 'error' => 'events array required']);
        }

        $events = [];
        foreach (array_slice(array_values($rawEvents), 0, self::MAX_EVENTS) as $event) {
            $clean = is_array($event) ? $this->validator->sanitize($event) : null;
            if ($clean !== null) {
                $events[] = $clean;
            }
        }
        if (!$events) {
            $result->setHttpResponseCode(400);

            return $result->setData(['ok' => false, 'error' => 'no valid events']);
        }

        try {
            $this->client->relayBrowse($events);
        } catch (EngineException $exception) {
            // Loss-tolerant by design; log at debug so a down engine cannot
            // flood the log from storefront traffic.
            $this->logger->debug('Browse relay failed', ['error' => $exception->getMessage()]);
        }

        return $result->setData(['ok' => true, 'accepted' => count($events)]);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
