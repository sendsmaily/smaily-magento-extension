<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;

/**
 * A Campaign Intelligence connection made before the connection recorded
 * its Smaily account (CON-237) gets it: the store-wide account's
 * subdomain, else the default website's (Connections::installationAccount()),
 * the account the setup exchange is made for. Engine\Connections groups
 * the websites of separate stores by it. One config row at the default
 * scope, no schema change; a store that is not connected, or whose
 * connection names its account already, is left as it is.
 */
class RecordEngineConnectionAccount implements DataPatchInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Connections $connections
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [MigrateLegacyConfig::class];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        if (!$this->settings->isConnected() || $this->settings->getSmailySubdomain() !== '') {
            return $this;
        }
        $account = $this->connections->installationAccount();
        if ($account !== '') {
            $this->settings->recordSmailySubdomain($account);
        }

        return $this;
    }
}
