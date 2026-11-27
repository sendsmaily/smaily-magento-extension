<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\StoreLayout;

/**
 * A store with several websites run as one store on one Smaily account
 * whose Smaily account, Contacts settings and automations only one website
 * holds (an initial setup on 3.0.0-rc1 to rc12 saved them at the chosen
 * website's scope) gives them to every website (CON-470): every other
 * website had no Smaily connection, so its signups never reached Smaily.
 * StoreLayout::shareSingleWebsiteSettings() decides and moves; any other
 * store is left as it is. Config and mapping rows only, no schema change.
 * The log line names the website and the moved paths, never a value.
 */
class ShareSingleWebsiteSettings implements DataPatchInterface
{
    public function __construct(
        private readonly StoreLayout $storeLayout,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [
            MigrateLegacyConfig::class,
            ConfirmContactsOfConnectedWebsites::class,
            CopyAutomationsToSeparateStores::class,
        ];
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
        foreach ($this->storeLayout->shareSingleWebsiteSettings() as $section => $moved) {
            $this->logger->info('Smaily Connect upgrade: one website\'s settings now apply to every website', [
                'section' => $section,
                'website_id' => $moved['website'],
                'paths' => $moved['paths'],
            ]);
        }

        return $this;
    }
}
