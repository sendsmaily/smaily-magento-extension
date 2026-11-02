<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;

/**
 * The engine's email designs (contract §22, §23) behind "Edit design" on
 * every email card (CON-257, as the WooCommerce plugin's CON-173):
 *
 * - designs() reads §23 — every email key the store can design, with its
 *   design state and subject. The admin reads it when the settings open
 *   and when the admin comes back from the design page; nothing before a
 *   send asks for it. The Smaily ids in it are not kept yet: Magento starts
 *   no email with a pushed workflow (docs/ARCHITECTURE.md "Email designs").
 * - designLink() mints a §22 one-time link to one email's design page. The
 *   link goes to the admin's browser once and is never stored, logged or
 *   cached here.
 *
 * Only an account the engine may be called for (connected, not refused —
 * Settings::isSendingAllowed()) is asked.
 */
class EmailDesigns
{
    /** §23 kinds; an item of any other kind is left out. */
    public const KINDS = ['engine', 'plugin', 'transactional'];

    /**
     * An email key (§22): a §11 catalogue key, or `plugin.<name>` /
     * `transactional.<name>` with `<name>` matching `^[a-z][a-z0-9_]{0,49}$`.
     */
    public const EMAIL_KEY_PATTERN = '/^(?:(?:plugin|transactional)\.[a-z][a-z0-9_]{0,49}|[a-z][a-z0-9_]{0,63})$/';

    /** §17 `return_url` limit, which §22 takes over. */
    private const RETURN_URL_MAX = 2048;

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client
    ) {
    }

    /** Whether the engine may be asked: connected and not refused. */
    public function isAvailable(): bool
    {
        return $this->settings->isSendingAllowed();
    }

    /**
     * The §23 list as the admin shows it: one item per email key, with the
     * design state and the subject. An item of an unknown kind, or without
     * a valid email key, is left out.
     *
     * @return array<int, array{email_key: string, kind: string, design_state: string, subject: string}>
     * @throws EngineException when the engine cannot answer
     */
    public function designs(): array
    {
        $body = $this->client->emailDesigns();
        $items = $body['emails'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $designs = [];
        foreach ($items as $item) {
            if (!is_array($item)
                || !is_string($item['email_key'] ?? null)
                || !self::isEmailKey($item['email_key'])
                || !in_array($item['kind'] ?? null, self::KINDS, true)) {
                continue;
            }
            $designs[] = [
                'email_key' => $item['email_key'],
                'kind' => (string)$item['kind'],
                'design_state' => is_string($item['design_state'] ?? null) ? $item['design_state'] : '',
                'subject' => is_string($item['subject'] ?? null) ? $item['subject'] : '',
            ];
        }

        return $designs;
    }

    /**
     * A one-time link to the design page of one email (§22). `$returnUrl`
     * goes along only when the engine would take it (allowedReturnUrl()),
     * else it is left out rather than refused by the engine.
     *
     * @return string the link: an https address, to open at once
     * @throws EngineException when the engine refuses or cannot answer
     */
    public function designLink(string $emailKey, string $returnUrl, string $language): string
    {
        if (!self::isEmailKey($emailKey)) {
            throw new EngineRequestException('unknown_email', 404, ['error' => 'unknown_email']);
        }
        $body = [];
        if (self::allowedReturnUrl($returnUrl, $this->client->siteUrl())) {
            $body['return_url'] = $returnUrl;
        }
        if (preg_match('/^[a-z]{2}$/', $language) === 1) {
            $body['language'] = $language;
        }

        $link = $this->client->emailDesignLink($emailKey, $body);
        $url = is_string($link['url'] ?? null) ? $link['url'] : '';
        if (!str_starts_with($url, 'https://')) {
            throw new EngineRequestException('invalid_engine_response', 502, ['error' => 'invalid_engine_response']);
        }

        return $url;
    }

    /** Whether `$key` has the form of a §22 email key. */
    public static function isEmailKey(string $key): bool
    {
        return preg_match(self::EMAIL_KEY_PATTERN, $key) === 1;
    }

    /** Whether `$key` names an engine email (a §11 catalogue key). */
    public static function isEngineEmail(string $key): bool
    {
        return !str_starts_with($key, 'plugin.') && !str_starts_with($key, 'transactional.');
    }

    /**
     * Whether the engine would take `$url` as the design page's way back
     * (§17): https, at most 2048 characters, on the host of the site URL the
     * extension sends as `plugin_info.site_url`.
     */
    public static function allowedReturnUrl(string $url, string $siteUrl): bool
    {
        if ($url === '' || strlen($url) > self::RETURN_URL_MAX) {
            return false;
        }
        $parts = parse_url($url);
        $siteHost = parse_url($siteUrl, PHP_URL_HOST);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && isset($parts['host'])
            && is_string($siteHost)
            && strtolower($parts['host']) === strtolower($siteHost);
    }

    /**
     * The origin (scheme://host[:port]) of a URL, or '' when it has none:
     * the engine posts its design page messages from the origin of its base
     * URL (§22 Embedding rule 5), and lets the page be framed by the origin
     * of the site URL (rule 1).
     */
    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $origin = $scheme . '://' . strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        // A browser leaves the scheme's own port out of an origin.
        if ($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            return $origin;
        }

        return $origin . ':' . $port;
    }
}
