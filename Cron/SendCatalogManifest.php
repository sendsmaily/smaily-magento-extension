<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Cron;

use Smaily\Connect\Model\Engine\CatalogManifest;

/**
 * Sends the nightly catalog manifest (contract §3c, PRO-3854) at 03:30 in
 * the admin's time zone — a quiet hour, after the 02:20 queue janitor. All
 * logic, including when not to send, is Engine\CatalogManifest's.
 */
class SendCatalogManifest
{
    public function __construct(
        private readonly CatalogManifest $catalogManifest
    ) {
    }

    public function execute(): void
    {
        $this->catalogManifest->send();
    }
}
