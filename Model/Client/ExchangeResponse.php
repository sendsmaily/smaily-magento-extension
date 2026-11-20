<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

/**
 * An HTTP reply as a queue row keeps it for the Log's Details (PRO-1965):
 * the status and the body when it is JSON, without any `message` in it —
 * the text Smaily or Campaign Intelligence wrote can name a customer
 * (CON-357); its codes and fields stay. A body that is not JSON (an HTML
 * error page) is not kept. Stored, never logged; the Details drawer shows it
 * through the PayloadRedactor. Shared by the Smaily and engine clients.
 */
class ExchangeResponse
{
    /**
     * @return array{http_status: int, body: mixed}
     */
    public static function of(int $status, string $body): array
    {
        return self::ofDecoded($status, json_decode($body, true));
    }

    /**
     * The same for a body the caller has already decoded.
     *
     * @param mixed $decoded the body decoded
     * @return array{http_status: int, body: mixed}
     */
    public static function ofDecoded(int $status, mixed $decoded): array
    {
        return [
            'http_status' => $status,
            'body' => is_array($decoded) ? self::withoutMessages($decoded) : null,
        ];
    }

    /**
     * @param array<int|string, mixed> $body
     * @return array<int|string, mixed>
     */
    private static function withoutMessages(array $body): array
    {
        unset($body['message']);
        foreach ($body as $key => $value) {
            if (is_array($value)) {
                $body[$key] = self::withoutMessages($value);
            }
        }

        return $body;
    }
}
