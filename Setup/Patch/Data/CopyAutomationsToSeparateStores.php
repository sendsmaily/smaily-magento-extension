<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Smaily\Connect\Model\StoreLayout;

/**
 * A store already upgraded whose websites hold separate Smaily accounts and
 * no store-wide one (CON-192: from 2.8.x, or set up on an earlier release
 * candidate) is separate stores (CON-279): each website gets the
 * store-wide automations it sent with as its own — config rows and mapping
 * rows at its website scope — so sending goes on exactly as before. Config
 * and mapping rows only, no schema change. Any other store is left as it is
 * (StoreLayout::copyAutomationsToSeparateAccounts()); a 2.8.x upgrade in
 * this setup run had MigrateLegacyConfig do the same first.
 */
class CopyAutomationsToSeparateStores implements DataPatchInterface
{
    public function __construct(
        private readonly StoreLayout $storeLayout
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
        $this->storeLayout->copyAutomationsToSeparateAccounts();

        return $this;
    }
}
