<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * HMAC tokens for abandoned cart restore links, keyed with the installation
 * crypt key — the link works without a session but cannot be forged or
 * enumerated. A link carries the moment it was issued (when the reminder was
 * created) inside the signature and expires 30 days later.
 *
 * A link from before links carried that moment (no `ts`) is accepted for 30
 * days after the tracker row's `mail_sent_at`, and is expired without one.
 */
class RestoreTokenManager
{
    public const VALID = 'valid';
    public const EXPIRED = 'expired';
    public const INVALID = 'invalid';

    private const LIFETIME_SECONDS = 30 * 86400;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly DateTime $dateTime,
        private readonly StateManager $stateManager
    ) {
    }

    /**
     * The restore link's query parameters for a link issued now.
     *
     * @return array{id: int, ts: int, token: string}
     */
    public function linkParams(int $quoteId): array
    {
        $issuedAt = (int)$this->dateTime->gmtTimestamp();

        return [
            'id' => $quoteId,
            'ts' => $issuedAt,
            'token' => $this->sign($quoteId . '|' . $issuedAt),
        ];
    }

    /**
     * VALID, EXPIRED (a genuine link past its lifetime) or INVALID.
     */
    public function check(int $quoteId, string $issuedAt, string $token): string
    {
        if ($quoteId <= 0 || $token === '') {
            return self::INVALID;
        }

        if ($issuedAt === '') {
            if (!hash_equals($this->sign((string)$quoteId), $token)) {
                return self::INVALID;
            }
            $sentAt = $this->stateManager->mailSentAt($quoteId);

            return $sentAt !== null && !$this->isPast((int)strtotime($sentAt . ' UTC'))
                ? self::VALID
                : self::EXPIRED;
        }

        if (!ctype_digit($issuedAt) || !hash_equals($this->sign($quoteId . '|' . $issuedAt), $token)) {
            return self::INVALID;
        }

        return $this->isPast((int)$issuedAt) ? self::EXPIRED : self::VALID;
    }

    private function isPast(int $issuedAt): bool
    {
        return (int)$this->dateTime->gmtTimestamp() > $issuedAt + self::LIFETIME_SECONDS;
    }

    private function sign(string $subject): string
    {
        return hash_hmac('sha256', 'smaily-cart-restore|' . $subject, $this->key());
    }

    private function key(): string
    {
        $raw = (string)$this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
        // Multiple keys are newline-separated after a key rotation; the
        // latest key signs new links (older links then expire, acceptable).
        $keys = array_values(array_filter(array_map('trim', explode("\n", $raw))));

        return $keys !== [] ? end($keys) : $raw;
    }
}
