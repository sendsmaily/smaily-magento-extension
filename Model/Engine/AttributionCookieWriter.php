<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Writes an attribution cookie from the store's server (`Set-Cookie`), so
 * Safari keeps it for its whole lifetime (contract §5: a cookie page
 * JavaScript sets lives 7 days there). One set of attributes for every
 * server-set attribution cookie — the campaign-click cookies
 * (Controller\Attribution\Land, CON-211) and the visitor token created at a
 * login (StoreVisitorToken, CON-260): the store's cookie domain and path,
 * Secure on HTTPS, SameSite=Lax and no HttpOnly, as the capture scripts
 * write them (contract "Cookie names").
 */
class AttributionCookieWriter
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly SessionConfig $sessionConfig
    ) {
    }

    /**
     * Sets cookie $name to $value for $days, or deletes it when $value is ''.
     *
     * @throws LocalizedException When the cookie cannot be sent (headers
     *         already sent, too many or too large cookies).
     */
    public function write(string $name, string $value, int $days): void
    {
        $metadata = $this->metadata();
        if ($value === '') {
            $this->cookieManager->deleteCookie($name, $metadata);

            return;
        }
        $metadata->setDuration($days * 86400);
        $this->cookieManager->setPublicCookie($name, $value, $metadata);
    }

    private function metadata(): PublicCookieMetadata
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata();
        $metadata->setDomain($this->sessionConfig->getCookieDomain());
        $metadata->setPath($this->sessionConfig->getCookiePath());
        $metadata->setSecure($this->request->isSecure());
        $metadata->setHttpOnly(false);
        $metadata->setSameSite('Lax');

        return $metadata;
    }
}
