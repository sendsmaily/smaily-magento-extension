<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Smaily\Connect\Model\RateLimit\FixedWindowCounter;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\StoreLayout;

/**
 * "Try Intelligence free" (contract §21, CON-363, as the WooCommerce
 * plugin's CON-175 OnboardingEndpoint): a shop with no Campaign
 * Intelligence connection starts its engine store from the admin, with the
 * Smaily API login the extension already holds.
 *
 * - The start is offered only while there is no engine connection, the
 *   shop is one store (not separate stores) and it sends with exactly one
 *   Smaily account, with no per-language accounts (§21 rule 3; the Magento
 *   sequence: "the button only for a shop with one Smaily account").
 * - Whether the engine takes starts is its §21 "Is the start open?"
 *   answer, kept STATUS_TTL seconds (its own max-age) as '1' or '0'; any
 *   failure is '0'. A 404 from the start writes '0' too.
 * - The start sends the MAIN Smaily account's login
 *   (ReportBuilder::mainCredentials(), the account the installation report
 *   uses), the shop's address (Client::siteUrl(), the address the setup
 *   exchange sends as `plugin_info.site_url`), the admin's email and
 *   language, and asks for a frame when the admin is on https, naming the
 *   admin's origin as `frame_origin` when it is not the shop's.
 * - Refusals are answered by the engine's status and code only, never its
 *   message. At most THROTTLE_MAX starts per THROTTLE_WINDOW seconds leave
 *   the installation (the engine takes 10 per 60 s per IP).
 *
 * The password goes only into the server-to-server body (Onboarding); the
 * one-time start link goes only to the admin's browser.
 */
class IntelligenceStart
{
    /** The engine's last status answer, '1' or '0'. */
    public const STATUS_CACHE_KEY = 'smaily_connect_onboarding_start_open';

    /** §21: the status answer carries `Cache-Control: public, max-age=300`. */
    public const STATUS_TTL = 300;

    /** A fixed-window counter key; the window's number follows it. */
    public const THROTTLE_KEY = 'smaily_connect_onboarding_starts_';

    public const THROTTLE_MAX = 10;
    public const THROTTLE_WINDOW = 60;

    public const PLATFORM = 'magento';

    public function __construct(
        private readonly Settings $settings,
        private readonly ReportBuilder $reportBuilder,
        private readonly StoreLayout $storeLayout,
        private readonly Onboarding $onboarding,
        private readonly Client $client,
        private readonly CacheInterface $cache,
        private readonly FixedWindowCounter $counter,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Whether this shop may start Campaign Intelligence itself, before the
     * engine is asked: no engine connection, one store, one Smaily account.
     */
    public function isOffered(): bool
    {
        return !$this->settings->isConnected()
            && !$this->storeLayout->isSeparateStores()
            && $this->reportBuilder->hasOneSmailyAccount();
    }

    /** The button shows: the start is offered, and the engine takes starts. */
    public function isStartOpen(): bool
    {
        return $this->isOffered() && $this->engineSaysOpen();
    }

    /**
     * Whether the start window may open in a frame over this admin: an
     * admin on https. The engine decides whether it accepts the origin
     * (§21 `frame_origin`); a refusal falls back to a new window.
     */
    public static function isFrameable(string $adminOrigin): bool
    {
        return str_starts_with($adminOrigin, 'https://');
    }

    /** §21 `language`: the start window in Estonian for an Estonian admin, else English. */
    public static function language(string $locale): string
    {
        return strtolower(substr($locale, 0, 2)) === 'et' ? 'et' : 'en';
    }

    /**
     * Ask the engine for a start link.
     *
     * @param bool $frame the admin asks for a frame
     * @param string $adminOrigin the origin of the admin page (scheme, host, port)
     * @param string $adminEmail the signed-in admin's email, '' when unknown
     * @param string $language `et` or `en`
     * @return array{status: int, body: array<string, string>} the answer for the admin's browser:
     *     200 {start_url, display, engine_origin}, else {error[, console_url]}
     */
    public function start(bool $frame, string $adminOrigin, string $adminEmail, string $language): array
    {
        if ($this->settings->isConnected()) {
            return self::refusal(409, 'already_connected');
        }
        $main = $this->reportBuilder->mainCredentials();
        if ($main === null) {
            return self::refusal(401, 'smaily_login_refused');
        }
        if (!$this->isOffered()) {
            return self::refusal(404, 'not_found');
        }
        $shopUrl = $this->client->siteUrl();
        if ($shopUrl === '') {
            return self::refusal(502, 'start_failed');
        }
        $window = intdiv((int)$this->dateTime->gmtTimestamp(), self::THROTTLE_WINDOW);
        if (!$this->counter->allow(self::THROTTLE_KEY . $window, self::THROTTLE_MAX, 2 * self::THROTTLE_WINDOW)) {
            return self::refusal(429, 'rate_limited');
        }

        $frame = $frame && self::isFrameable($adminOrigin);
        $body = [
            'shop_url' => $shopUrl,
            'platform' => self::PLATFORM,
            'smaily_subdomain' => $main->subdomain,
            'smaily_username' => $main->username,
            'smaily_password' => $main->password,
            'language' => $language,
        ];
        if ($frame) {
            $body['display'] = 'frame';
            // §21 Magento: the admin's origin when the admin has its own host.
            if ($adminOrigin !== EmailDesigns::origin($shopUrl)) {
                $body['frame_origin'] = $adminOrigin;
            }
        }
        if ($adminEmail !== '') {
            $body['admin_email'] = $adminEmail;
        }

        ['status' => $status, 'body' => $answer] = $this->onboarding->start($body);
        if ($status !== 200) {
            return $this->engineRefusal($status, $answer);
        }

        $startUrl = is_string($answer['start_url'] ?? null) ? $answer['start_url'] : '';
        if (!self::isEngineUrl($startUrl)) {
            return self::refusal(502, 'start_failed');
        }

        return [
            'status' => 200,
            'body' => [
                'start_url' => $startUrl,
                // A frame only when asked for and the engine says so (§21).
                'display' => $frame && ($answer['display'] ?? '') === 'frame' ? 'frame' : 'window',
                'engine_origin' => EmailDesigns::origin($startUrl),
            ],
        ];
    }

    /**
     * The engine's status answer, kept STATUS_TTL seconds.
     */
    private function engineSaysOpen(): bool
    {
        $cached = $this->cache->load(self::STATUS_CACHE_KEY);
        if ($cached === '1' || $cached === '0') {
            return $cached === '1';
        }
        $open = $this->onboarding->isStartOpen();
        $this->remember($open);

        return $open;
    }

    private function remember(bool $open): void
    {
        $this->cache->save($open ? '1' : '0', self::STATUS_CACHE_KEY, [], self::STATUS_TTL);
    }

    /**
     * A §21 refusal by the engine's status and code only — never its message.
     *
     * @param array<string, mixed> $answer
     * @return array{status: int, body: array<string, string>}
     */
    private function engineRefusal(int $status, array $answer): array
    {
        $code = is_string($answer['error'] ?? null) ? $answer['error'] : '';

        if ($status === 400 && $code === 'frame_origin_not_allowed') {
            return self::refusal(400, $code);
        }
        if ($status === 401) {
            return self::refusal(401, 'smaily_login_refused');
        }
        if ($status === 403 && $code === 'smaily_paid_package_required') {
            return self::refusal(403, $code);
        }
        if ($status === 404) {
            // The start closed after the status was read: the button goes
            // away on the next page load too.
            $this->remember(false);

            return self::refusal(404, 'not_found');
        }
        if ($status === 409) {
            $refusal = self::refusal(409, 'store_exists');
            $consoleUrl = is_string($answer['console_url'] ?? null) ? $answer['console_url'] : '';
            if (self::isEngineUrl($consoleUrl)) {
                $refusal['body']['console_url'] = $consoleUrl;
            }

            return $refusal;
        }
        if ($status === 429) {
            return self::refusal(429, 'rate_limited');
        }
        if ($status === 0 || $status >= 500) {
            return self::refusal(502, 'unavailable');
        }

        return self::refusal(502, 'start_failed');
    }

    /**
     * An https address on the engine's host, with a path: the only start
     * link and console address passed on to the admin's browser.
     */
    private static function isEngineUrl(string $url): bool
    {
        $engineOrigins = array_map(static fn (string $host): string => 'https://' . $host, Client::ENGINE_HOSTS);

        return in_array(EmailDesigns::origin($url), $engineOrigins, true)
            && str_starts_with((string)parse_url($url, PHP_URL_PATH), '/');
    }

    /**
     * @return array{status: int, body: array<string, string>}
     */
    private static function refusal(int $status, string $error): array
    {
        return ['status' => $status, 'body' => ['error' => $error]];
    }
}
