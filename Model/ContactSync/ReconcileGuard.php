<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

/**
 * Reentrancy guard for Smaily -> Magento reconcile writes.
 *
 * While the reconciler mirrors Smaily consent state onto newsletter
 * subscribers, the subscriber-save observer skips — otherwise the write
 * would echo straight back to Smaily (and a Smaily delete mirrored to
 * Magento would re-create the deleted contact, fighting GDPR erasure).
 *
 * Shared DI instance: both the reconciler and the observers must receive
 * the same object.
 */
class ReconcileGuard
{
    private bool $applying = false;

    public function isApplying(): bool
    {
        return $this->applying;
    }

    /**
     * Run $fn with the guard set, restoring the previous value (nesting-safe).
     */
    public function runSuppressed(callable $fn): void
    {
        $previous = $this->applying;
        $this->applying = true;
        try {
            $fn();
        } finally {
            $this->applying = $previous;
        }
    }
}
