<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * A shopper's current recommendations from the engine (contract §15 — the
 * same products as in the shopper's Smaily contact fields), for the
 * "Smaily recommendations" widget. Mirrors the WooCommerce plugin's
 * StorefrontRecommendations.
 *
 * Who the engine is asked about comes from server state only (identity()):
 *   - a logged-in shopper by the store customer id, and only while profiling
 *     is allowed for them (ProfilingConsent). A shopper who objected is never
 *     asked about, not by the visitor token either;
 *   - a guest by the shape-checked visitor-token cookie
 *     (AttributionManager::readCookies()), and only when Magento's cookie
 *     notice does not hold the shopper's consent back
 *     (`isUserNotAllowSaveCookie()`: restriction mode on and not accepted on
 *     this website). The storefront script asks only with consent; this is
 *     the half of that rule the server can see;
 *   - nobody else.
 * Always only while the engine may be called (`isSendingAllowed()`), in one
 * attempt (Client).
 *
 * The answer, an empty one included (§15: do not retry), is cached for an
 * hour under the tenant and a hash of the identifier type and value. A
 * failure is cached as an empty answer for 10 minutes, and a timeout, a
 * network failure or a 5xx also pauses every shopper's engine call for 2
 * minutes: a cache miss then answers empty at once, so a hanging engine
 * cannot hold a PHP worker per guest (Woo PRO-3857).
 */
class StorefrontRecommendations
{
    /** Product cards per widget. */
    public const LIMIT = 4;

    private const CACHE_TTL_SECONDS = 3600;
    private const FAILURE_CACHE_TTL_SECONDS = 600;
    private const PAUSE_TTL_SECONDS = 120;

    private const CACHE_PREFIX = 'smaily_recs_';
    private const PAUSE_KEY = 'smaily_recs_paused';

    private const ID_CUSTOMER = 'customer';
    private const ID_VISITOR = 'visitor';

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly ProfilingConsent $profilingConsent,
        private readonly AttributionManager $attributionManager,
        private readonly CustomerSession $customerSession,
        private readonly CookieHelper $cookieHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly Logger $logger
    ) {
    }

    /**
     * The current shopper's recommendations in the engine's order — empty
     * whenever the engine may not or need not be asked.
     *
     * @return array<int, array{rec_id: string, external_id: string, sku: string}>
     */
    public function slots(): array
    {
        if (!$this->settings->isSendingAllowed()) {
            return [];
        }

        $identity = $this->identity();
        if ($identity === null) {
            return [];
        }

        $cacheKey = self::CACHE_PREFIX . hash(
            'sha256',
            $this->settings->getTenantId() . '|' . $identity['type'] . '|' . $identity['id']
        );
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached)) {
            $slots = json_decode($cached, true);

            return is_array($slots) ? $slots : [];
        }

        if ($this->cache->load(self::PAUSE_KEY) !== false) {
            return [];
        }

        try {
            $answer = $this->client->recommendations([$identity['key'] => $identity['id']], self::LIMIT);
        } catch (EngineException $exception) {
            $this->logger->debug('Storefront recommendations: the engine call failed', [
                'error' => $exception->getMessage(),
            ]);
            $this->cache->save('[]', $cacheKey, [], self::FAILURE_CACHE_TTL_SECONDS);
            if ($exception instanceof EngineTransportException
                && ($exception->getHttpStatus() === 0 || $exception->getHttpStatus() >= 500)
            ) {
                $this->cache->save('1', self::PAUSE_KEY, [], self::PAUSE_TTL_SECONDS);
            }

            return [];
        }

        $slots = $this->usableSlots($answer);
        $this->cache->save((string)json_encode($slots), $cacheKey, [], self::CACHE_TTL_SECONDS);

        return $slots;
    }

    /**
     * Who to ask about, or null for nobody: the identifier type (in the
     * cache key), the request body's key for it, and the value.
     *
     * @return array{type: string, key: string, id: string}|null
     */
    private function identity(): ?array
    {
        if ($this->customerSession->isLoggedIn()) {
            $customerId = (int)$this->customerSession->getCustomerId();
            try {
                $email = (string)$this->customerSession->getCustomerData()->getEmail();
            } catch (\Exception) {
                return null;
            }
            if ($customerId <= 0 || $email === ''
                || !$this->profilingConsent->isAllowed($email, $this->storeManager->getStore()->getId())
            ) {
                return null;
            }

            return ['type' => self::ID_CUSTOMER, 'key' => 'customer_external_id', 'id' => (string)$customerId];
        }

        if ($this->cookieHelper->isUserNotAllowSaveCookie()) {
            return null;
        }
        $token = $this->attributionManager->readCookies()['visitor_token'];

        return $token === null ? null : ['type' => self::ID_VISITOR, 'key' => 'smaily_visitor_token', 'id' => $token];
    }

    /**
     * The slots with a well-formed recommendation id and a product key
     * (`external_id`, else `sku`), in the engine's order.
     *
     * @param array<string, mixed> $answer the §15 response body
     * @return array<int, array{rec_id: string, external_id: string, sku: string}>
     */
    private function usableSlots(array $answer): array
    {
        $slots = is_array($answer['slots'] ?? null) ? $answer['slots'] : [];
        $usable = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $recId = is_string($slot['rec_id'] ?? null) ? $slot['rec_id'] : '';
            $externalId = is_scalar($slot['external_id'] ?? null) ? trim((string)$slot['external_id']) : '';
            $sku = is_string($slot['sku'] ?? null) ? trim($slot['sku']) : '';
            if (!RecId::isValid($recId) || ($externalId === '' && $sku === '')) {
                continue;
            }
            $usable[] = ['rec_id' => $recId, 'external_id' => $externalId, 'sku' => $sku];
        }

        return array_slice($usable, 0, self::LIMIT);
    }
}
