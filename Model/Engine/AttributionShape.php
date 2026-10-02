<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

/**
 * The shapes the attribution signals other than `smaily_rec_id` are accepted
 * in: the visitor token, the context and the anonymous session id (contract
 * §5 and "Cookie names"). The same definitions as the WooCommerce plugin's
 * AttributionShape (PRO-1942), applied at both ends: when the cookies are
 * stamped onto the order (AttributionManager) and when the order is sent
 * (OrderPayloadBuilder). An off-shape value is dropped, never cut short, and
 * the order keeps its other signals (PRO-3584).
 *
 * The engine issues visitor tokens as `vt_` + 10 alphanumerics. One
 * difference from WooCommerce: the whole token is capped at 64 characters,
 * the width of the side table's visitor_token column, so a value this class
 * accepts always fits. The context and the session id keep WooCommerce's
 * 64-character context charset; every session id this module generates is a
 * UUID, which fits it. The D modifier keeps `$` from accepting a trailing
 * newline.
 */
class AttributionShape
{
    private const VISITOR_TOKEN_PATTERN = '/^vt_[A-Za-z0-9]{1,61}$/D';

    private const CONTEXT_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/D';

    public static function isVisitorToken(string $value): bool
    {
        return preg_match(self::VISITOR_TOKEN_PATTERN, $value) === 1;
    }

    public static function isContext(string $value): bool
    {
        return preg_match(self::CONTEXT_PATTERN, $value) === 1;
    }

    public static function isSessionId(string $value): bool
    {
        return preg_match(self::CONTEXT_PATTERN, $value) === 1;
    }
}
