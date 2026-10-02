<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

/**
 * Prepares stored queue payloads/responses for display in the admin log
 * drill-down: values under secret-looking keys (password, token, api key,
 * ...) are never shown — replaced with "[redacted]", at any depth.
 *
 * Contact data is shown in full, as the WooCommerce plugin's log does: the
 * Log is an admin debugging tool behind its own ACL resource. Non-JSON input
 * (a raw error body, an HTML response) is shown as it is.
 */
class PayloadRedactor
{
    private const REDACTED = '[redacted]';

    private const SECRET_KEY_PATTERN =
        '/pass|secret|token|api[_-]?key|authorization|signature|credential/i';

    /**
     * Redact a stored payload/response string for display. JSON is walked
     * recursively and re-encoded pretty-printed; anything else is treated
     * as plain text.
     */
    public function redact(?string $raw): string
    {
        if ($raw === null || trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $this->redactDecoded($decoded) : $raw;
    }

    /**
     * The same for a payload the caller has already decoded — the Details
     * drawer decodes the stored payload once and hands it straight over.
     *
     * @param array<int|string, mixed> $data
     */
    public function redactDecoded(array $data): string
    {
        $encoded = json_encode(
            $this->redactArray($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        return $encoded === false ? self::REDACTED : $encoded;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $result[$key] = self::REDACTED;
                continue;
            }
            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
                continue;
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
