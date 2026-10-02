<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;
use Smaily\Connect\Model\SmailyUrl;

/**
 * Boot data for the native setup wizard AND the tabbed settings page (both
 * render the same step partials): saved settings for prefill, store
 * environment for guidance texts, and connection state for step gating.
 */
class WizardData implements ArgumentInterface
{
    /** @var array{orders: int, products: int}|null */
    private ?array $storeTotals = null;

    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly EngineSettings $engineSettings,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AccountResolver $accountResolver,
        private readonly ContactAudience $contactAudience,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Json $serializer,
        private readonly MappingCollectionFactory $mappingCollectionFactory,
        private readonly WebsiteContext $websiteContext,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly ResolverInterface $localeResolver,
        private readonly ConsentSource $consentSource
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
     * The initial setup step the page opens on, drawn server-side so the
     * page does not jump when the script runs (PRO-2456): Connect on a fresh
     * install and on a finished setup (its summary), Contacts once the
     * Smaily credentials are filled in but the setup is not finished.
     */
    public function getStartStep(): int
    {
        if ($this->isSetupCompleted()) {
            return 1;
        }

        return $this->config->isConnected($this->websiteContext->getStoreId()) ? 2 : 1;
    }

    /**
     * The furthest initial setup step reached on load: every step after a
     * finished setup, else the step it opens on. Steps up to it stay
     * unlocked in the step rail.
     */
    public function getReachedStep(): int
    {
        return $this->isSetupCompleted() ? 5 : $this->getStartStep();
    }

    /**
     * The website the page/wizard currently targets — feeds the Settings
     * selector's/wizard chooser's own chrome (RFC_MULTI_WEBSITE.md §2).
     */
    public function getSelectedWebsiteId(): int
    {
        return $this->websiteContext->getWebsiteId();
    }

    /**
     * Whether this request explicitly named a website (as opposed to
     * resolving the installation's default one) — the wizard uses this to
     * decide whether its website-chooser step still needs to be shown.
     */
    public function hasSelectedWebsite(): bool
    {
        return $this->websiteContext->isExplicit();
    }

    /**
     * More than one real website on this install? The selector/chooser only
     * ever render when true; single-website installs never see them.
     */
    public function hasMultipleWebsites(): bool
    {
        return $this->websiteContext->hasMultipleWebsites();
    }

    /**
     * Every real website, id => name, for the selector/chooser controls.
     *
     * @return array<int, string>
     */
    public function getWebsiteOptions(): array
    {
        return $this->websiteContext->getWebsiteOptions();
    }

    /**
     * The `_query` fragment carrying the current target website, for any
     * admin URL that must land back on (or post to) the same website the
     * page was rendered with — the one shared definition of that mechanism.
     *
     * @return array{_query: array{website: int}}
     */
    public function getWebsiteQuery(): array
    {
        return ['_query' => ['website' => $this->websiteContext->getWebsiteId()]];
    }

    /**
     * Whether Smaily accepted the saved credentials at the last real check
     * (PRO-3560) — what the Connection status shows. Rendered into the page
     * so no other state shows before the script runs (PRO-3570).
     */
    public function isSmailyVerified(): bool
    {
        return $this->verifiedCredentials->isVerified($this->websiteContext->getStoreId());
    }

    /**
     * The saved Smaily subdomain of the selected website.
     */
    public function getSavedSubdomain(): string
    {
        return $this->config->getSubdomain($this->websiteContext->getStoreId());
    }

    /**
     * The saved Smaily API username of the selected website.
     */
    public function getSavedUsername(): string
    {
        return $this->config->getUsername($this->websiteContext->getStoreId());
    }

    /**
     * The saved storefront address of the selected website ('' when none,
     * PRO-3660).
     */
    public function getSavedStorefrontUrl(): string
    {
        return $this->config->getStorefrontUrl($this->websiteContext->getStoreId());
    }

    public function getBootJson(): string
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $storeId = $this->websiteContext->getStoreId();

        return $this->serializer->serialize([
            'connected' => $this->config->isConnected($storeId),
            // What the Connection status shows: Smaily accepted these saved
            // credentials at the last real check (PRO-3560). `connected`
            // above only says they are filled in.
            'verified' => $this->isSmailyVerified(),
            // Why not: the package has no API access, not the credentials (PRO-3579).
            'planBlocked' => $this->verifiedCredentials->isPlanBlocked($storeId),
            'setupCompleted' => $this->isSetupCompleted(),
            'storeId' => $storeId,
            'connection' => [
                'subdomain' => $this->config->getSubdomain($storeId),
                'username' => $this->config->getUsername($storeId),
                'hasPassword' => $this->config->getPassword($storeId) !== '',
                'multilingualMode' => $this->getMultilingualMode(),
            ],
            'multilingual' => [
                'languages' => $this->accountResolver->detectedLanguages($websiteId),
                'fallbackLanguage' => $this->config->getFallbackLanguage(),
            ],
            'subscribers' => [
                'syncEnabled' => $this->config->isSyncEnabled($websiteId),
                'syncMode' => $this->mode->mode($websiteId),
                'syncFields' => $this->config->getSyncFields($websiteId),
                'includeGuests' => $this->config->includeGuests($websiteId),
                'checkoutOptin' => $this->config->isCheckoutOptinEnabled($websiteId),
                'suppressOptinEmails' => $this->config->suppressOptinEmails($websiteId),
            ],
            'automations' => [
                'welcomeEnabled' => $this->config->isWelcomeEnabled($websiteId),
                'welcomeWorkflow' => (string)($this->config->getWelcomeWorkflow($websiteId) ?: ''),
                'firstOrderEnabled' => $this->config->isFirstOrderEnabled($websiteId),
                'firstOrderWorkflow' => (string)($this->config->getFirstOrderWorkflow($websiteId) ?: ''),
                'abandonedEnabled' => $this->config->isAbandonedCartEnabled($websiteId),
                'abandonedWorkflow' => (string)($this->config->getAbandonedCartWorkflow($websiteId) ?: ''),
                'abandonedCutoff' => $this->config->getAbandonedCutoffMinutes($websiteId),
            ],
            'intelligence' => [
                'connected' => $this->engineSettings->isConnected(),
                'tenantName' => $this->engineSettings->getTenantName()
                    ?: $this->engineSettings->getTenantId(),
                'engineVersion' => $this->engineSettings->getEngineVersion(),
                'browseTracking' => $this->engineSettings->isBrowseTrackingEnabled(),
            ],
            'rss' => [
                'enabled' => $this->config->isRssEnabled(),
            ],
            'totals' => $this->getStoreTotals(),
        ]);
    }

    /**
     * Campaign Intelligence has refused this account outright (contract §2
     * `403 tenant_inactive`), remembered locally so the panel can say what
     * happened instead of promising an outage will pass (PRO-2451).
     */
    public function isEngineRefused(): bool
    {
        return $this->engineSettings->isRefused();
    }

    /**
     * Whether the browse tracker's consent comes from Magento's cookie
     * notice in every store view; otherwise the panel recommends connecting
     * a consent source (PRO-3664).
     */
    public function isCookieRestrictionOnEverywhere(): bool
    {
        return $this->consentSource->isCookieRestrictionOnEverywhere();
    }

    /**
     * The User Guide section on connecting a cookie consent tool.
     */
    public function getConsentGuideUrl(): string
    {
        return ConsentSource::GUIDE_URL;
    }

    /**
     * The merchant's own Smaily account page — where a deactivated Campaign
     * Intelligence account is sorted out. Falls back to the public site when
     * no subdomain is configured yet.
     */
    public function getSmailyAccountUrl(): string
    {
        $subdomain = $this->config->getSubdomain($this->websiteContext->getStoreId());

        return $subdomain === '' ? 'https://smaily.com' : SmailyUrl::forSubdomain($subdomain);
    }

    /**
     * The selected website's stored contact-sync answer — the same one the
     * live paths and the contacts import read (PRO-1764). Read by the WIZARD
     * only: Settings carries the switch itself, so panel/panels-js.phtml owns
     * the import control's state there, from `subscribers.syncEnabled` above.
     */
    public function isSyncEnabled(): bool
    {
        return $this->config->isSyncEnabled($this->websiteContext->getWebsiteId());
    }

    /**
     * Saved sync-field selection for the server-rendered step-2 checkboxes.
     *
     * @return string[]
     */
    public function getSelectedSyncFields(): array
    {
        return $this->config->getSyncFields($this->websiteContext->getWebsiteId());
    }

    /**
     * The selected website's saved contact-sync mode — the one the contacts
     * import reads when it runs.
     */
    public function getSyncMode(): string
    {
        return $this->mode->mode($this->websiteContext->getWebsiteId());
    }

    /**
     * How many contacts the contacts import would send from the selected
     * website under each contact-sync mode (PRO-3582) — the estimate follows
     * the mode picked on the panel.
     *
     * @return array<string, int> mode => contacts
     */
    public function getContactImportCounts(): array
    {
        $counts = [];
        foreach ([
            SyncMode::MODE_CONSENT,
            SyncMode::MODE_LEGITIMATE_INTEREST,
            SyncMode::MODE_CHECKOUT_OPTIN,
        ] as $mode) {
            $counts[$mode] = $this->contactAudience->count($this->websiteContext->getWebsiteId(), $mode);
        }

        return $counts;
    }

    /**
     * Two collection counts, asked for twice per render (the template and
     * the boot JSON), so they are counted once per request.
     *
     * @return array{orders: int, products: int}
     */
    public function getStoreTotals(): array
    {
        return $this->storeTotals ??= [
            'orders' => $this->orderCollectionFactory->create()->getSize(),
            'products' => $this->productCollectionFactory->create()->getSize(),
        ];
    }

    /**
     * The effective multilingual mode: single-language installations are
     * locked to 'single' (the panels hide the mode cards for them).
     */
    public function getMultilingualMode(): string
    {
        if (!$this->isMultilingual()) {
            return MultilingualMode::MODE_SINGLE;
        }

        return $this->config->getMultilingualMode($this->websiteContext->getWebsiteId())
            ?: MultilingualMode::MODE_SINGLE;
    }

    /**
     * More than one distinct storefront language configured?
     */
    public function isMultilingual(): bool
    {
        return count($this->accountResolver->detectedLanguages($this->websiteContext->getWebsiteId())) > 1;
    }

    /**
     * @return string[]
     */
    public function getDetectedLanguages(): array
    {
        return $this->accountResolver->detectedLanguages($this->websiteContext->getWebsiteId());
    }

    /**
     * The language whose account is the mode-A default fallback ('' = none
     * picked yet).
     */
    public function getFallbackLanguage(): string
    {
        return $this->config->getFallbackLanguage();
    }

    /**
     * Per-language account state for the mode-A credential blocks: saved
     * store-view scoped credentials of a representative store view per
     * detected language.
     *
     * @return array<int, array{language: string, storeId: int|null, subdomain: string,
     *     username: string, hasPassword: bool, languageName: string, verified: bool}>
     */
    public function getMultilingualAccounts(): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $accounts = [];
        foreach ($this->accountResolver->languageStoreIds($websiteId) as $language => $storeId) {
            $accounts[] = [
                'language' => $language,
                'storeId' => $storeId,
                'subdomain' => $this->config->getSubdomain($storeId),
                'username' => $this->config->getUsername($storeId),
                'hasPassword' => $this->config->getPassword($storeId) !== '',
                // The block's heading and status (PRO-3566): the language
                // by name in the admin's own language, and whether Smaily
                // accepted this store view's saved credentials (PRO-3560).
                'languageName' => $this->languageName($language),
                'verified' => $this->verifiedCredentials->isVerified($storeId),
            ];
        }

        return $accounts;
    }

    /**
     * A language code ("et") as its name in the admin's interface locale
     * ("Estonian", or "eesti" for an Estonian admin); the code in capitals
     * when the locale data has no name for it.
     */
    private function languageName(string $language): string
    {
        $name = \Locale::getDisplayLanguage($language, $this->localeResolver->getLocale());

        return is_string($name) && $name !== '' && strcasecmp($name, $language) !== 0
            ? $name
            : strtoupper($language);
    }

    /**
     * Saved workflow-mapping rows for the target website, keyed
     * "trigger|language" for template prefill. Legacy website_id=0 rows
     * (pre-Phase-3 saves, the 2.8.x migration's default-scope seeding) are
     * read as a fallback for a key the target website hasn't saved its own
     * row for yet — the same website-beats-global preference
     * `Model\Automation\Router` applies at dispatch time.
     *
     * @return array<string, array{workflowId: int, isDefaultFallback: bool}>
     */
    public function getSavedMappings(): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['in' => [$websiteId, 0]])
            ->addFieldToFilter('trigger_type', ['in' => Trigger::ALL])
            ->setOrder('website_id', 'ASC');

        $rows = [];
        /** @var Mapping $mapping */
        foreach ($collection as $mapping) {
            $rows[$mapping->getData('trigger_type') . '|' . $mapping->getData('language')] = [
                'workflowId' => $mapping->getWorkflowId(),
                'isDefaultFallback' => (bool)$mapping->getData('is_default_fallback'),
            ];
        }

        return $rows;
    }
}
