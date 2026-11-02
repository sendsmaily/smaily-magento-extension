<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\Exception\LocalizedException;
use Smaily\Connect\Model\Logger\Logger;

/**
 * A store-created visitor token for a shopper who logs in (contract §7
 * "Store-created token at login", v1.22.0; CON-260). A shopper who only
 * ever logs in has no visitor token, so after logout the storefront could
 * not ask for their recommendations (§15). The login identity merge
 * (Observer\Engine\CustomerLogin) creates one, sets it in the visitor-token
 * cookie and sends it in the merge, which binds it to the customer.
 *
 * The format is the contract's store-created token (§5): `vs_` + exactly 22
 * characters `[A-Za-z0-9]`, each from PHP's cryptographically secure
 * `random_int()` (about 131 random bits) — the WooCommerce plugin's
 * `LandingCapture::issue_visitor_token()`. The cookie is the visitor-token
 * cookie, set by the server with the name and lifetime of the engine config
 * (AttributionManager::getClientConfig()) and the attributes of every
 * server-set attribution cookie (AttributionCookieWriter).
 *
 * The caller decides whether a token may be created (the shopper's
 * marketing consent and no visitor token yet); this class only creates it.
 */
class StoreVisitorToken
{
    public const PREFIX = 'vs_';

    public const LENGTH = 22;

    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function __construct(
        private readonly AttributionManager $attributionManager,
        private readonly AttributionCookieWriter $cookieWriter,
        private readonly Logger $logger
    ) {
    }

    /**
     * A new `vs_` token.
     */
    public static function generate(): string
    {
        $last = strlen(self::ALPHABET) - 1;
        $token = self::PREFIX;
        for ($i = 0; $i < self::LENGTH; $i++) {
            $token .= self::ALPHABET[random_int(0, $last)];
        }

        return $token;
    }

    /**
     * Creates a token and sets it in the visitor-token cookie.
     *
     * @return string|null The token, or null when the cookie could not be
     *         set: a token the browser does not hold is not sent.
     */
    public function issue(): ?string
    {
        $config = $this->attributionManager->getClientConfig();
        $name = (string)$config['cookieVisitor'];
        if ($name === '') {
            return null;
        }

        $token = self::generate();
        try {
            $this->cookieWriter->write($name, $token, (int)$config['ttlVisitorDays']);
        } catch (LocalizedException $exception) {
            $this->logger->debug('Visitor token not created at login: the cookie could not be set', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        }

        return $token;
    }
}
