<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

/**
 * Marks a subscription the shopper made on the storefront outside the
 * frontend area: the checkout opt-in is saved while the order is placed,
 * and Luma's checkout places the order through the REST API. The
 * subscriber-save observer reads it to decide whether the welcome
 * automation fires (PRO-3580) and, in the checkout-opt-in-only mode,
 * whether the subscription syncs at all (PRO-3606). Only the checkout
 * opt-in sets it.
 *
 * Shared DI instance: the order-placed observer and the subscriber-save
 * observer must receive the same object.
 */
class StorefrontSubscription
{
    private bool $active = false;

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Run $fn with the mark set, restoring the previous value (nesting-safe).
     */
    public function run(callable $fn): void
    {
        $previous = $this->active;
        $this->active = true;
        try {
            $fn();
        } finally {
            $this->active = $previous;
        }
    }
}
