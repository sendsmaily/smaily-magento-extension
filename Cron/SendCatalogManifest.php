<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Engine\CatalogManifest;
use Smaily\Connect\Model\Engine\Connections;

/**
 * Sends the nightly catalog manifest (contract §3c, PRO-3854) at 03:30 in
 * the admin's time zone — a quiet hour, after the 02:20 queue janitor —
 * one list per Campaign Intelligence connection (CON-237), the primary
 * first. All logic, including when not to send, is Engine\CatalogManifest's.
 */
class SendCatalogManifest
{
    public function __construct(
        private readonly CatalogManifest $catalogManifest,
        private readonly Connections $connections
    ) {
    }

    public function execute(): void
    {
        foreach ($this->connections->all() as $connection) {
            $this->catalogManifest->send($connection);
        }
    }
}
