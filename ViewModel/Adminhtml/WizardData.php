<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ContactSync\Mode;
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
    /** @var array{customers: int, orders: int, products: int}|null */
    private ?array $storeTotals = null;

    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly EngineSettings $engineSettings,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AccountResolver $accountResolver,
        private readonly CustomerCollectionFactory $customerCollectionFactory,
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Json $serializer,
        private readonly MappingCollectionFactory $mappingCollectionFactory,
        private readonly WebsiteContext $websiteContext,
        private readonly VerifiedCredentials $verifiedCredentials
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

    public function getBootJson(): string
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $storeId = $this->websiteContext->getStoreId();

        return $this->serializer->serialize([
            'connected' => $this->config->isConnected($storeId),
            // What the Connection status shows: Smaily accepted these saved
            // credentials at the last real check (PRO-3560). `connected`
            // above only says they are filled in.
            'verified' => $this->verifiedCredentials->isVerified($storeId),
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
     * Three collection counts, asked for twice per render (the template and
     * the boot JSON), so they are counted once per request.
     *
     * @return array{customers: int, orders: int, products: int}
     */
    public function getStoreTotals(): array
    {
        return $this->storeTotals ??= [
            'customers' => $this->customerCollectionFactory->create()->getSize(),
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
     *     username: string, hasPassword: bool}>
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
            ];
        }

        return $accounts;
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
