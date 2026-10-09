<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\ModuleVersion;

/**
 * Wizard-first gating for the Smaily Connect admin pages
 * (docs/internal/RFC_MULTI_WEBSITE.md §2):
 * - The initial setup's Finish step saves the setup-completed flag for its
 *   one website (website scope). Dashboard, Settings and Log redirect a
 *   website without the flag to the initial setup of that website. A
 *   website has no flag on a fresh install, when it is newly added, and,
 *   after an upgrade from 2.8.x, on every website: the legacy migration
 *   does not write the flag, so each website goes through the initial
 *   setup (intended, owner decision 2026-10-09).
 * - After a MAJOR version upgrade a one-time "review what's new" notice is
 *   also posted; it does not replace the redirect.
 */
class SetupGuard
{
    public const XML_PATH_LAST_SEEN_VERSION = 'smaily_connect/internal/last_seen_version';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly NotifierInterface $notifier,
        private readonly ModuleVersion $moduleVersion,
        private readonly WebsiteContext $websiteContext
    ) {
    }

    public function isSetupCompleted(): bool
    {
        return $this->scopeConfig->isSetFlag(
            WizardStepSaver::XML_PATH_SETUP_COMPLETED,
            ScopeInterface::SCOPE_WEBSITE,
            $this->websiteContext->getWebsiteId()
        );
    }

    /**
     * The route params of a page's redirect to the initial setup: the
     * website the request named, so the setup opens for that website
     * instead of on its website chooser, which starts on the first website
     * (PRO-4012). A request that named no website gets the chooser.
     *
     * @return array<string, mixed>
     */
    public function getWizardRouteParams(): array
    {
        return $this->websiteContext->isExplicit()
            ? ['_query' => ['website' => $this->websiteContext->getWebsiteId()]]
            : [];
    }

    /**
     * Record the running module version; on a major-version jump post the
     * one-time upgrade notice. Called from every Smaily Connect admin page.
     */
    public function checkVersionChange(): void
    {
        $current = $this->moduleVersion->current();
        if ($current === '') {
            return;
        }

        $lastSeen = (string)$this->scopeConfig->getValue(self::XML_PATH_LAST_SEEN_VERSION);
        if ($lastSeen === $current) {
            return;
        }

        if ($lastSeen !== ''
            && $this->moduleVersion->major($lastSeen) !== $this->moduleVersion->major($current)
        ) {
            $this->notifier->addNotice(
                (string)__('Smaily Connect was upgraded to version %1', $current),
                (string)__(
                    'A major upgrade can bring new features and changed screens.'
                    . ' Review the settings under Marketing > Smaily Connect > Settings,'
                    . ' or re-run the initial setup — your saved configuration is untouched either way.'
                )
            );
        }

        $this->configWriter->save(self::XML_PATH_LAST_SEEN_VERSION, $current);
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }
}
