<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;

/**
 * The merchant's lawful-basis preset and the policy it implies (per website).
 *
 * Mirrors the WooCommerce plugin's ContactSyncMode:
 * - legitimate_interest: all registered customers, no opt-in filter, no
 *   reconcile.
 * - consent (DEFAULT): only opted-in newsletter subscribers; bidirectional
 *   Smaily<->Magento reconcile.
 * - checkout_optin: no account sync; checkout checkbox only (guests
 *   intrinsically included).
 *
 * An unknown stored value falls back to the lawful-safe default so a bogus
 * mode never broadens the audience. No mode lets an automation re-subscribe
 * a contact who unsubscribed in Smaily (PRO-3577, Queue\Handler\AutomationHandler).
 */
class Mode
{
    public const DEFAULT_MODE = SyncMode::MODE_CONSENT;

    private const VALID_MODES = [
        SyncMode::MODE_CONSENT,
        SyncMode::MODE_LEGITIMATE_INTEREST,
        SyncMode::MODE_CHECKOUT_OPTIN,
    ];

    public function __construct(
        private readonly Config $config
    ) {
    }

    public function mode(?int $websiteId = null): string
    {
        $raw = $this->config->getSyncMode($websiteId);

        return in_array($raw, self::VALID_MODES, true) ? $raw : self::DEFAULT_MODE;
    }

    /**
     * Registered customers are synced in every preset except checkout-only.
     */
    public function syncsAccounts(?int $websiteId = null): bool
    {
        return $this->mode($websiteId) !== SyncMode::MODE_CHECKOUT_OPTIN;
    }

    /**
     * A contact must have opted in under consent + checkout; not under
     * legitimate interest.
     */
    public function requiresOptin(?int $websiteId = null): bool
    {
        return $this->mode($websiteId) !== SyncMode::MODE_LEGITIMATE_INTEREST;
    }

    /**
     * Guest-order emails are synced — intrinsic to checkout-only, a toggle
     * (default off) otherwise. A guest order without the checkout opt-in is
     * sent only where requiresOptin() is false (Observer\OrderPlaced,
     * PRO-3606); elsewhere only the opt-in sends a guest.
     */
    public function includeGuests(?int $websiteId = null): bool
    {
        if ($this->mode($websiteId) === SyncMode::MODE_CHECKOUT_OPTIN) {
            return true;
        }

        return $this->config->includeGuests($websiteId);
    }

    /**
     * The store mirrors Smaily's unsubscribes back into Magento (consent only).
     */
    public function reconciles(?int $websiteId = null): bool
    {
        return $this->mode($websiteId) === SyncMode::MODE_CONSENT;
    }
}
