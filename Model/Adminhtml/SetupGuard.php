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
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\ModuleVersion;

/**
 * Wizard-first gating for the Smaily Connect admin pages:
 * - The initial setup sets the whole store up (CON-179): its Finish step
 *   saves the setup-completed flag once for the store (default scope).
 *   Until the store is set up, Dashboard, Settings and Log redirect to the
 *   initial setup; a website is never sent there on its own. The store is
 *   not set up on a fresh install and after an upgrade from 2.8.x (the
 *   legacy migration does not write the flag; intended, owner decision
 *   2026-10-09).
 * - An initial setup finished before CON-179 saved the flag per website: a
 *   store where any website holds it counts as set up, so a finished store
 *   is not sent back into the setup (nothing is rewritten).
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

    /**
     * The gate of Dashboard, Settings and Log: record the module version
     * (checkVersionChange()), then the redirect to the initial setup when
     * the store is not set up yet, else null so the page renders.
     */
    public function redirectIfNotSetUp(ResultFactory $resultFactory): ?Redirect
    {
        $this->checkVersionChange();
        if ($this->isSetupCompleted()) {
            return null;
        }
        /** @var Redirect $redirect */
        $redirect = $resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('smaily_connect/wizard');
    }

    /**
     * Whether the store is set up: the store's mark, or (an initial setup
     * finished before CON-179) any website's own.
     */
    public function isSetupCompleted(): bool
    {
        if ($this->scopeConfig->isSetFlag(WizardStepSaver::XML_PATH_SETUP_COMPLETED)) {
            return true;
        }
        foreach (array_keys($this->websiteContext->getWebsiteOptions()) as $websiteId) {
            if ($this->scopeConfig->isSetFlag(
                WizardStepSaver::XML_PATH_SETUP_COMPLETED,
                ScopeInterface::SCOPE_WEBSITE,
                $websiteId
            )) {
                return true;
            }
        }

        return false;
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
