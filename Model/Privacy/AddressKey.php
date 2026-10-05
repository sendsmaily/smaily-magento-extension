<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;

/**
 * The keyed hash a shopper's address is known by wherever the module must
 * not keep the address itself (PRO-3575, PRO-3765, PRO-3767): the opt-out
 * record's entries (ProfilingOptOuts), the consent cache keys
 * (ProfilingConsent), the entity of a profiling-consent queue row and of a
 * contact row whose address is longer than the entity column
 * (Queue\ContactEntity), and what the GDPR erasure matches (LocalEraser).
 *
 * HMAC-SHA256 with the installation crypt key over the address, lower case
 * and trimmed — normalised here, so every caller gets the same key for the
 * same shopper. The crypt key is read and parsed once per instance.
 */
class AddressKey
{
    /** @var string[]|null the crypt keys, newest first */
    private ?array $cryptKeys = null;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * The address as every key is made of it: lower case, trimmed.
     */
    public static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * The address's current key: the HMAC with the newest crypt key.
     */
    public function of(string $email): string
    {
        return $this->keys($email)[0];
    }

    /**
     * The keys an address may be known by: the current form first (HMAC with
     * the newest crypt key), then the earlier crypt keys, then the plain sha1
     * of the opt-out record's first form.
     *
     * @return string[]
     */
    public function keys(string $email): array
    {
        $email = self::normalise($email);

        $keys = [];
        foreach ($this->cryptKeys() as $cryptKey) {
            $keys[] = hash_hmac('sha256', 'smaily-profiling-optout|' . $email, $cryptKey);
        }
        $keys[] = sha1($email);

        return $keys;
    }

    /**
     * @return string[]
     */
    private function cryptKeys(): array
    {
        if ($this->cryptKeys === null) {
            $raw = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
            // Multiple keys are newline-separated after a key rotation, newest last.
            $this->cryptKeys = array_reverse(array_values(array_filter(array_map('trim', explode("\n", $raw)))));
        }

        return $this->cryptKeys;
    }
}
