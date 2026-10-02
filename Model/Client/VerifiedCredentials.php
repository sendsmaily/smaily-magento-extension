<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use Smaily\Connect\Model\Config;

/**
 * Whether Smaily accepted the saved credentials at the last real check
 * (PRO-3560) — what the Dashboard and the Connection status call
 * "Connected". Config::isConnected() only says that the three fields are
 * filled in; it stays the gate for queueing.
 *
 * SmailyClient records the answers at its one chokepoint: a passed
 * credential check (Test connection, a connection save) accepts the
 * credentials, and any 401/403 refusal — the queue's included — refuses
 * them. Credentials are remembered as keyed hashes, never in plain text, so
 * changed credentials are not connected until they are checked.
 *
 * A third answer (PRO-3579): Smaily code 227, "a paid package is
 * required". Smaily gives it before it checks the credentials, so they are
 * neither accepted nor refused — the store is not connected, because every
 * request is refused, and the reason is the package.
 */
class VerifiedCredentials
{
    public const FLAG_CODE = 'smaily_connect_verified_credentials';
    public const PLAN_BLOCKED_FLAG_CODE = 'smaily_connect_plan_blocked_credentials';

    /** Bounded because every passed Test connection is remembered. */
    public const MAX_REMEMBERED = 20;

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly EncryptorInterface $encryptor,
        private readonly Config $config
    ) {
    }

    /**
     * Whether Smaily accepted the credentials saved for this store.
     *
     * @param int|string|null $storeId
     */
    public function isVerified(int|string|null $storeId = null): bool
    {
        return $this->isRemembered(self::FLAG_CODE, $this->storeCredentials($storeId));
    }

    /**
     * Whether Smaily accepted the credentials saved for this website — the
     * single account, or with per-language accounts the default fallback
     * account: the account a connection save checks (PRO-3719).
     */
    public function isWebsiteVerified(int $websiteId): bool
    {
        return $this->isRemembered(self::FLAG_CODE, $this->websiteCredentials($websiteId));
    }

    /**
     * Whether Smaily's last answer for the credentials saved for this
     * website was that the account's package does not include API access.
     */
    public function isWebsitePlanBlocked(int $websiteId): bool
    {
        return $this->isRemembered(self::PLAN_BLOCKED_FLAG_CODE, $this->websiteCredentials($websiteId));
    }

    public function accept(string $subdomain, string $username, string $password): void
    {
        $fingerprint = $this->fingerprint($subdomain, $username, $password);
        $this->forget(self::PLAN_BLOCKED_FLAG_CODE, $fingerprint);
        $this->remember(self::FLAG_CODE, $fingerprint);
    }

    public function refuse(string $subdomain, string $username, string $password): void
    {
        $fingerprint = $this->fingerprint($subdomain, $username, $password);
        $this->forget(self::PLAN_BLOCKED_FLAG_CODE, $fingerprint);
        $this->forget(self::FLAG_CODE, $fingerprint);
    }

    public function planBlocked(string $subdomain, string $username, string $password): void
    {
        $fingerprint = $this->fingerprint($subdomain, $username, $password);
        $this->forget(self::FLAG_CODE, $fingerprint);
        $this->remember(self::PLAN_BLOCKED_FLAG_CODE, $fingerprint);
    }

    /**
     * @param int|string|null $storeId
     * @return string[] subdomain, username, password
     */
    private function storeCredentials(int|string|null $storeId): array
    {
        return [
            $this->config->getSubdomain($storeId),
            $this->config->getUsername($storeId),
            $this->config->getPassword($storeId),
        ];
    }

    /**
     * @return string[] subdomain, username, password
     */
    private function websiteCredentials(int $websiteId): array
    {
        return [
            $this->config->getWebsiteSubdomain($websiteId),
            $this->config->getWebsiteUsername($websiteId),
            $this->config->getWebsitePassword($websiteId),
        ];
    }

    /**
     * @param string[] $credentials subdomain, username, password
     */
    private function isRemembered(string $flagCode, array $credentials): bool
    {
        if (in_array('', $credentials, true)) {
            return false;
        }

        return in_array($this->fingerprint(...$credentials), $this->remembered($flagCode), true);
    }

    private function remember(string $flagCode, string $fingerprint): void
    {
        $remembered = $this->remembered($flagCode);
        if (in_array($fingerprint, $remembered, true)) {
            return;
        }

        $remembered[] = $fingerprint;
        $this->flagManager->saveFlag($flagCode, array_slice($remembered, -self::MAX_REMEMBERED));
    }

    private function forget(string $flagCode, string $fingerprint): void
    {
        $remembered = $this->remembered($flagCode);
        if (!in_array($fingerprint, $remembered, true)) {
            return;
        }

        $this->flagManager->saveFlag($flagCode, array_values(array_diff($remembered, [$fingerprint])));
    }

    /**
     * @return string[]
     */
    private function remembered(string $flagCode): array
    {
        $data = $this->flagManager->getFlagData($flagCode);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    private function fingerprint(string $subdomain, string $username, string $password): string
    {
        return $this->encryptor->hash((string)json_encode([$subdomain, $username, $password]));
    }
}
