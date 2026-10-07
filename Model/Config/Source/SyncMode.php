<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Contact-sync lawful-basis presets (aligned with the WooCommerce plugin's
 * ContactSyncMode; see docs/CONTACT_SYNC_MODES.md in the Woo repo).
 */
class SyncMode implements OptionSourceInterface
{
    public const MODE_CONSENT = 'consent';
    public const MODE_LEGITIMATE_INTEREST = 'legitimate_interest';
    public const MODE_CHECKOUT_OPTIN = 'checkout_optin';

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_CONSENT, 'label' => __('Subscribers only (consent)')],
            ['value' => self::MODE_LEGITIMATE_INTEREST, 'label' => __('All customers (legitimate interest)')],
            ['value' => self::MODE_CHECKOUT_OPTIN, 'label' => __('Checkout opt-in only')],
        ];
    }
}
