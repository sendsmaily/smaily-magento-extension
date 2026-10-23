<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Attribution;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\AttributionShape;
use Smaily\Connect\Model\Engine\RecId;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\RateLimit\PerAddressLimiter;

/**
 * The campaign-click cookies set by the store's server (POST
 * smaily/attribution/land, CON-211). Safari keeps a cookie that page
 * JavaScript sets for 7 days at most (24 hours after a link with tracking
 * parameters), so the 365-day visitor-token cookie lived days; a cookie
 * from a `Set-Cookie` header keeps its lifetime (contract §5). The landing
 * page itself is served from the full-page cache, so the landing-capture
 * script (js/attribution.js; Hyvä: smaily-attribution.js) posts the click's
 * values here after its own shape checks, and writes the cookies itself only
 * when this request fails. A POST is never served from the full-page cache.
 *
 * The rules are the capture script's, as the WooCommerce plugin's
 * LandingCapture: a well-formed rec id sets `smaily_rec_id` and sets the
 * context cookie to a well-formed context or deletes it (the context cookie
 * rule); a visitor token is taken only in the engine's `vt_` form, never a
 * store-created `vs_` one (CON-177). Not gated on consent, as the capture
 * script is not: a functional first-party cookie for the merchant's own
 * email click. Names and lifetimes come from the engine config
 * (AttributionManager::getClientConfig()); the cookies take the store's
 * cookie domain and path, Secure on HTTPS, SameSite=Lax and no HttpOnly
 * (contract "Cookie names").
 *
 * CSRF-exempt: the landing page may come from the full-page cache, so it has
 * no session form key. A request gives no more than a link with the same
 * parameters does. 404 when the engine is not connected; limited per
 * address as the browse relay; 400 when nothing in it has its shape; every
 * answer is `Cache-Control: no-store, private` with an empty body.
 */
class Land implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const RATE_LIMIT_PER_MINUTE = 30;
    private const CACHE_CONTROL = 'no-store, private';

    public function __construct(
        private readonly HttpRequest $request,
        private readonly ResultFactory $resultFactory,
        private readonly Settings $settings,
        private readonly AttributionManager $attributionManager,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly SessionConfig $sessionConfig,
        private readonly PerAddressLimiter $limiter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(): Raw
    {
        if (!$this->settings->isConnected()) {
            return $this->answer(404);
        }
        if (!$this->limiter->allow('smaily_land_', self::RATE_LIMIT_PER_MINUTE)) {
            return $this->answer(429);
        }

        $config = $this->attributionManager->getClientConfig();
        $recId = $this->field('rec_id');
        $visitorToken = $this->field('visitor_token');
        $context = $this->field('context');

        // name => [value ('' deletes the cookie), lifetime in days]
        $cookies = [];
        if (RecId::isValid($recId)) {
            $cookies[(string)$config['cookieRecId']] = [$recId, (int)$config['ttlRecIdDays']];
            $cookies[(string)$config['cookieContext']] = [
                AttributionShape::isContext($context) ? $context : '',
                (int)$config['ttlContextDays'],
            ];
        }
        if (AttributionShape::isEngineVisitorToken($visitorToken)) {
            $cookies[(string)$config['cookieVisitor']] = [$visitorToken, (int)$config['ttlVisitorDays']];
        }
        if ($cookies === []) {
            return $this->answer(400);
        }

        try {
            foreach ($cookies as $name => [$value, $days]) {
                $this->write($name, $value, $days);
            }
        } catch (LocalizedException) {
            // The script writes the cookies itself when this request fails.
            return $this->answer(500);
        }

        return $this->answer(204);
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

    /**
     * Sets cookie $name to $value for $days, or deletes it when $value is ''.
     *
     * @throws LocalizedException
     */
    private function write(string $name, string $value, int $days): void
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
        $metadata->setDomain($this->sessionConfig->getCookieDomain());
        $metadata->setPath($this->sessionConfig->getCookiePath());
        $metadata->setSecure($this->request->isSecure());
        $metadata->setHttpOnly(false);
        $metadata->setSameSite('Lax');

        if ($value === '') {
            $this->cookieManager->deleteCookie($name, $metadata);

            return;
        }
        $metadata->setDuration($days * 86400);
        $this->cookieManager->setPublicCookie($name, $value, $metadata);
    }

    private function field(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }

    private function answer(int $status): Raw
    {
        /** @var Raw $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setHttpResponseCode($status);
        $result->setContents('');
        $result->setHeader('Cache-Control', self::CACHE_CONTROL, true);

        return $result;
    }
}
