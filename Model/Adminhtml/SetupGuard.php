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
 * Wizard-first gating for the Smaily Connect admin pages:
 * - On a fresh install (setup not completed) every Smaily Connect page
 *   redirects to the setup wizard.
 * - After a MAJOR version upgrade a one-time "review what's new" notice is
 *   posted instead of a hard redirect — the store keeps running on the
 *   migrated settings.
 *
 * The completed flag is website-scoped (RFC_MULTI_WEBSITE.md §2, Phase 2):
 * it reads via the normal website-falls-back-to-default chain, so an
 * existing single-website install is unaffected (its default-scope flag
 * still resolves as "completed" for its one website), while a newly added
 * second website that inherits the same value via fallback is treated as
 * already set up too — consistent with every other field in this module,
 * which the merchant can still give its own distinct settings via the
 * Settings page's website selector or by revisiting the wizard's steps.
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
