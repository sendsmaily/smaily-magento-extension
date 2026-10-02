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
 */
class VerifiedCredentials
{
    public const FLAG_CODE = 'smaily_connect_verified_credentials';

    /** Bounded because every passed Test connection is remembered. */
    public const MAX_REMEMBERED = 20;

    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly EncryptorInterface $encryptor,
        private readonly Config $config
    ) {
    }

    /**
     * @param int|string|null $storeId
     */
    public function isVerified(int|string|null $storeId = null): bool
    {
        return $this->config->isConnected($storeId)
            && in_array($this->fingerprint(
                $this->config->getSubdomain($storeId),
                $this->config->getUsername($storeId),
                $this->config->getPassword($storeId)
            ), $this->remembered(), true);
    }

    public function accept(string $subdomain, string $username, string $password): void
    {
        $fingerprint = $this->fingerprint($subdomain, $username, $password);
        $remembered = $this->remembered();
        if (in_array($fingerprint, $remembered, true)) {
            return;
        }

        $remembered[] = $fingerprint;
        $this->flagManager->saveFlag(self::FLAG_CODE, array_slice($remembered, -self::MAX_REMEMBERED));
    }

    public function refuse(string $subdomain, string $username, string $password): void
    {
        $fingerprint = $this->fingerprint($subdomain, $username, $password);
        $remembered = $this->remembered();
        if (!in_array($fingerprint, $remembered, true)) {
            return;
        }

        $this->flagManager->saveFlag(
            self::FLAG_CODE,
            array_values(array_diff($remembered, [$fingerprint]))
        );
    }

    /**
     * @return string[]
     */
    private function remembered(): array
    {
        $data = $this->flagManager->getFlagData(self::FLAG_CODE);

        return is_array($data) ? array_values(array_filter($data, 'is_string')) : [];
    }

    private function fingerprint(string $subdomain, string $username, string $password): string
    {
        return $this->encryptor->hash((string)json_encode([$subdomain, $username, $password]));
    }
}
