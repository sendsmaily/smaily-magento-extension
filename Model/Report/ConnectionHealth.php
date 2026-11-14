<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The last answer each connection got — what the installation report says
 * about a connection's health (CON-234; contract installation-report-v1
 * "Connections", as the WooCommerce plugin's CON-290).
 *
 * For each Smaily account (by subdomain) and each Campaign Intelligence
 * engine store (by tenant id) it keeps whether the most recent request
 * failed, with its error CODE, and when the last request that writes data
 * succeeded. The two HTTP chokepoints report every answer here:
 * `Client\SmailyClient::request()` (a 2xx POST answered with code 101 is a
 * sync: every POST writes contacts or triggers a workflow) and
 * `Engine\Client::request()` (a 2xx POST to an `/ingest/` path is a sync).
 *
 * It stores codes only, never a message, a body or a customer identifier.
 * The storefront's browse relay passes the engine chokepoint, so a success
 * that changes nothing but the time is written at most once per
 * WRITE_EVERY_SECONDS; a change of status or error code is written at once.
 * Recording never throws: a failed write costs the health, never the
 * request.
 */
class ConnectionHealth
{
    public const FLAG_CODE = 'smaily_connect_connection_health';

    public const STATUS_OK = 'ok';
    public const STATUS_FAILING = 'failing';

    /** Smaily answered code 227: the account's package has no API. */
    public const PLAN_BLOCKED = 'plan_blocked';
    /** Any other 4xx except 429 (a wrong subdomain answers 404). */
    public const CREDENTIALS_REJECTED = 'credentials_rejected';
    /** A 429, a 5xx, a timeout or a network error. */
    public const UNREACHABLE = 'unreachable';
    /** Campaign Intelligence sent no answer. */
    public const NETWORK_ERROR = 'network_error';

    /** A success that changes nothing but the time is written at most this often. */
    public const WRITE_EVERY_SECONDS = 300;

    /** The contract's code rule; any other code is stored as UNKNOWN_CODE. */
    public const CODE_PATTERN = '/^[a-z0-9_]{1,64}$/';

    /** The most entries kept per group; the oldest go first. */
    public const MAX_ENTRIES = 50;

    private const UNKNOWN_CODE = 'unknown_error';

    private const GROUP_SMAILY = 'smaily';
    private const GROUP_ENGINE = 'engine';

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * A Smaily API answer: '' for a success, else its error code. $wrote is
     * true when the request wrote data (contacts, a workflow trigger).
     */
    public function recordSmaily(string $subdomain, string $errorCode, bool $wrote): void
    {
        $this->record(self::GROUP_SMAILY, strtolower(trim($subdomain)), $errorCode, $wrote);
    }

    /**
     * A Campaign Intelligence answer for one engine store: '' for a success,
     * else the engine's error code. $wrote is true for an accepted ingest
     * request.
     */
    public function recordEngine(string $tenantId, string $errorCode, bool $wrote): void
    {
        $this->record(self::GROUP_ENGINE, trim($tenantId), $errorCode, $wrote);
    }

    /**
     * The health of one Smaily account, in the report's shape.
     *
     * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
     */
    public function smaily(string $subdomain): array
    {
        return $this->shape($this->entry(self::GROUP_SMAILY, strtolower(trim($subdomain))));
    }

    /**
     * The health of one engine store, in the report's shape.
     *
     * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
     */
    public function engine(string $tenantId): array
    {
        return $this->shape($this->entry(self::GROUP_ENGINE, trim($tenantId)));
    }

    private function record(string $group, string $key, string $errorCode, bool $wrote): void
    {
        if ($key === '') {
            return;
        }

        try {
            $all = $this->all();
            $entries = is_array($all[$group] ?? null) ? $all[$group] : [];
            $entry = is_array($entries[$key] ?? null) ? $entries[$key] : [];

            $now = (int)$this->dateTime->gmtTimestamp();
            $code = $errorCode === '' ? '' : (preg_match(self::CODE_PATTERN, $errorCode) === 1
                ? $errorCode
                : self::UNKNOWN_CODE);
            $old = isset($entry['error_code']) ? (string)$entry['error_code'] : null;
            $last = (int)($entry['last_sync_at'] ?? 0);

            $changed = $old !== $code;
            $synced = $code === '' && $wrote && $last <= $now - self::WRITE_EVERY_SECONDS;
            if (!$changed && !$synced) {
                return;
            }

            $entry['error_code'] = $code;
            if ($code === '' && $wrote) {
                $entry['last_sync_at'] = $now;
            }

            // The newest entry goes last, so the cap drops the oldest.
            unset($entries[$key]);
            $entries[$key] = $entry;
            $all[$group] = array_slice($entries, -self::MAX_ENTRIES, null, true);

            $this->flagManager->saveFlag(self::FLAG_CODE, $all);
        } catch (\Throwable) {
            // The health is best effort; the request it describes goes on.
            return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $group, string $key): array
    {
        try {
            $all = $this->all();
        } catch (\Throwable) {
            return [];
        }
        $entries = is_array($all[$group] ?? null) ? $all[$group] : [];

        return is_array($entries[$key] ?? null) ? $entries[$key] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function all(): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array{status: string, last_sync_at: ?int, last_error_code: ?string}
     */
    private function shape(array $entry): array
    {
        $code = (string)($entry['error_code'] ?? '');
        $last = (int)($entry['last_sync_at'] ?? 0);

        return [
            'status' => $code === '' ? self::STATUS_OK : self::STATUS_FAILING,
            'last_sync_at' => $last > 0 ? $last : null,
            'last_error_code' => $code === '' ? null : $code,
        ];
    }
}
