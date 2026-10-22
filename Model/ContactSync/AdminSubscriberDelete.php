<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

/**
 * Marks the deletes of the admin's Newsletter Subscribers grid (CON-176).
 * The grid's Delete action deletes each selected row through the subscriber
 * model; the subscriber-delete observer unsubscribes a contact in Smaily only
 * while this mark is set. A customer account's deletion deletes its
 * subscriber row through the same model, and that sends nothing, as in the
 * WooCommerce plugin.
 *
 * Shared DI instance: the mass-delete plugin and the subscriber-delete
 * observer must receive the same object.
 */
class AdminSubscriberDelete
{
    private bool $active = false;

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Run $fn with the mark set, restoring the previous value (nesting-safe).
     */
    public function run(callable $fn): mixed
    {
        $previous = $this->active;
        $this->active = true;
        try {
            return $fn();
        } finally {
            $this->active = $previous;
        }
    }
}
