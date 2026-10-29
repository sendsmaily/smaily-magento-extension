<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

/**
 * Matching and anonymisation of the blobs a queue row stores — the queued
 * payload, the payload as sent and the last API response — for an Art. 17
 * erasure (PRO-2452).
 *
 * **Matching decodes first.** A stored payload is JSON, and PHP's json_encode
 * escapes non-ASCII by default: the `õ` of `mõni@näide.test` reaches the
 * column as a `\u00f5` sequence, so a raw substring search on the address
 * silently misses the row (the sibling's open PRO-2448). Comparison
 * therefore happens on the DECODED values, where the address is itself
 * again. Only a blob that is not JSON at all (a raw error body, an HTML
 * response) is searched as plain text — there is no escaping there to
 * defeat it.
 *
 * **Only the whole address matches** (CON-240), in any letter case: a value
 * that is the address, or free text (an error message) that names it. An
 * address that merely contains it — `jane@example.test` for
 * `ane@example.test` — is another shopper's, so the erasure and the export
 * never reach that shopper's rows, as in the WooCommerce plugin.
 *
 * **A blob is decoded once.** `decode()` is the caller's first step; the
 * result is then handed to `matches()` and, for a row that is kept, to
 * `anonymize()`, which is why both take the raw blob and its decoding.
 *
 * **Anonymisation is an allowlist by construction.** Every scalar is
 * replaced with the placeholder; the keys and the structure survive, so the
 * Log drill-down still shows the shape of what went out. A payload field
 * added later cannot leak by simply not being on a denylist.
 */
class PayloadAnonymizer
{
    /**
     * A stored blob as a structure, or null when it is empty or not JSON.
     *
     * @return array<int|string, mixed>|null
     */
    public function decode(?string $stored): ?array
    {
        if ($stored === null || trim($stored) === '') {
            return null;
        }

        $decoded = json_decode($stored, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a stored blob mentions this contact's whole address.
     *
     * @param array<int|string, mixed>|null $decoded what decode() made of it
     */
    public function matches(?string $stored, ?array $decoded, string $email): bool
    {
        if ($stored === null || trim($stored) === '' || trim($email) === '') {
            return false;
        }

        $pattern = $this->addressPattern($email);
        if ($decoded === null) {
            return preg_match($pattern, mb_scrub($stored)) === 1;
        }

        return $this->containsEmail($decoded, $pattern);
    }

    /**
     * Replace every value in a stored blob with the placeholder, keeping the
     * keys and the structure. A blob that is not decodable JSON is replaced
     * wholesale — it may be anything, so nothing in it can be assumed
     * impersonal.
     *
     * @param array<int|string, mixed>|null $decoded what decode() made of it
     */
    public function anonymize(?string $stored, ?array $decoded): ?string
    {
        if ($stored === null || trim($stored) === '') {
            return $stored;
        }

        if ($decoded === null) {
            return Erasure::PLACEHOLDER;
        }

        return (string)json_encode($this->redact($decoded), JSON_UNESCAPED_SLASHES);
    }

    /**
     * The address as a whole, in any letter case: not preceded by a
     * character an address's local part goes on with, and not followed by
     * one its domain goes on with — `jane@example.test` and
     * `ane@example.test.ee` are other people's, while the address alone, in
     * quotes or at the end of a sentence still is this one (CON-240).
     */
    private function addressPattern(string $email): string
    {
        return '/(?<![\p{L}\p{N}._%+\-])' . preg_quote(mb_scrub(trim($email)), '/')
            . '(?![\p{L}\p{N}_\-]|\.[\p{L}\p{N}])/iu';
    }

    /**
     * @param array<int|string, mixed> $data
     */
    private function containsEmail(array $data, string $pattern): bool
    {
        foreach ($data as $value) {
            if (is_array($value)) {
                if ($this->containsEmail($value, $pattern)) {
                    return true;
                }
                continue;
            }
            if (is_string($value) && preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function redact(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $result[$key] = is_array($value) ? $this->redact($value) : Erasure::PLACEHOLDER;
        }

        return $result;
    }
}
