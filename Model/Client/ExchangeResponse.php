<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

/**
 * An HTTP reply as a queue row keeps it for the Log's Details (PRO-1965):
 * the status and the body — decoded when it is JSON, else the start of the
 * text (an HTML error page). Stored, never logged; the Details drawer shows
 * it through the PayloadRedactor. Shared by the Smaily and engine clients.
 */
class ExchangeResponse
{
    private const MAX_TEXT_LENGTH = 2000;

    /**
     * @return array{http_status: int, body: mixed}
     */
    public static function of(int $status, string $body): array
    {
        return self::ofDecoded($status, $body, json_decode($body, true));
    }

    /**
     * The same for a body the caller has already decoded.
     *
     * @param mixed $decoded $body decoded
     * @return array{http_status: int, body: mixed}
     */
    public static function ofDecoded(int $status, string $body, mixed $decoded): array
    {
        return [
            'http_status' => $status,
            'body' => is_array($decoded) ? $decoded : mb_substr($body, 0, self::MAX_TEXT_LENGTH),
        ];
    }
}
