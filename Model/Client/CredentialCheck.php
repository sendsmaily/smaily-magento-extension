<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Config;

/**
 * Asks Smaily whether it accepts a set of credentials posted from the admin
 * (the wizard's connection step and the system configuration save).
 *
 * The client is built from the posted values, not from the stored
 * configuration, because the configuration cache can still hold the values
 * from before this request.
 */
class CredentialCheck
{
    /** The admin shows a stored password as asterisks; posting them back keeps it. */
    private const KEPT_PASSWORD_PATTERN = '/^\*+$/';

    /**
     * @param SmailyClientFactory $clientFactory
     * @param Config $config
     */
    public function __construct(
        private readonly SmailyClientFactory $clientFactory,
        private readonly Config $config
    ) {
    }

    /**
     * Whether a posted password is the masked placeholder for the stored one.
     *
     * @param string $password
     * @return bool
     */
    public function isKeptPassword(string $password): bool
    {
        return preg_match(self::KEPT_PASSWORD_PATTERN, $password) === 1;
    }

    /**
     * The password to check: the posted one, or the stored one when it is kept.
     *
     * A post keeps the stored password when it carries no password or the
     * masked placeholder.
     *
     * @param string|null $posted
     * @param int|string|null $storeId
     * @return string
     */
    public function resolvePassword(?string $posted, int|string|null $storeId): string
    {
        if ($posted === null || $this->isKeptPassword($posted)) {
            return $this->config->getPassword($storeId);
        }

        return $posted;
    }

    /**
     * Asks Smaily whether it accepts the credentials.
     *
     * SmailyClient remembers the answer for the status displays.
     *
     * @param string $subdomain
     * @param string $username
     * @param string $password
     * @return bool false when a credential is empty and Smaily was not asked
     * @throws SmailyClientException when Smaily refuses the credentials
     *         (AuthenticationException) or cannot be reached
     */
    public function check(string $subdomain, string $username, string $password): bool
    {
        if ($subdomain === '' || $username === '' || $password === '') {
            return false;
        }

        $this->clientFactory->create([
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
        ])->validateCredentials();

        return true;
    }
}
