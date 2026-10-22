<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
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
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;
use Smaily\Connect\Model\SmailyUrl;

/**
 * Boot data for the native setup wizard AND the tabbed settings page (both
 * render the same step partials): saved settings for prefill, store
 * environment for guidance texts, and connection state for step gating.
 *
 * The initial setup reads the store-wide values (CON-179): WebsiteContext
 * gives website 0 on a 2+-website install, whose reads resolve the default
 * scope, and the one website on a single-website install.
 */
class WizardData implements ArgumentInterface
{
    /** The automations' workflows in system config. */
    private const WORKFLOW_PATHS = [
        'welcomeWorkflow' => Config::XML_PATH_WELCOME_WORKFLOW,
        'firstOrderWorkflow' => Config::XML_PATH_FIRST_ORDER_WORKFLOW,
        'abandonedWorkflow' => Config::XML_PATH_ABANDONED_WORKFLOW,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly Mode $mode,
        private readonly EngineSettings $engineSettings,
        private readonly AccountResolver $accountResolver,
        private readonly ContactAudience $contactAudience,
        private readonly Json $serializer,
        private readonly MappingCollectionFactory $mappingCollectionFactory,
        private readonly WebsiteContext $websiteContext,
        private readonly VerifiedCredentials $verifiedCredentials,
        private readonly ResolverInterface $localeResolver,
        private readonly ConsentSource $consentSource,
        private readonly OrderOrigin $orderOrigin,
        private readonly SetupGuard $setupGuard,
        private readonly WebsiteOverrides $overrides
    ) {
    }

    /**
     * Whether the store is set up — one mark for the store (CON-179).
     */
    public function isSetupCompleted(): bool
    {
        return $this->setupGuard->isSetupCompleted();
    }

    /**
     * The initial setup step the page opens on, drawn server-side so the
     * page does not jump when the script runs (PRO-2456): always Connect —
     * the form until it is confirmed, even when Smaily credentials are
     * saved already, as in the WooCommerce plugin (CON-179), and on a
     * finished setup its summary.
     */
    public function getStartStep(): int
    {
        return 1;
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
     * The website the Settings page currently targets — feeds its website
     * selector.
     */
    public function getSelectedWebsiteId(): int
    {
        return $this->websiteContext->getWebsiteId();
    }

    /**
     * Whether the page sets the whole store up: the initial setup (CON-179).
     */
    public function isStoreWide(): bool
    {
        return $this->websiteContext->isStoreWide();
    }

    /**
     * Whether the selected website holds its own values of a section
     * (WebsiteOverrides) — the state of its "own" switch on the Settings
     * tab. Always false on a single-website install and in the initial
     * setup.
     */
    public function hasOwnValues(string $section): bool
    {
        return $this->isOwnRequired($section)
            || ($this->websiteContext->hasMultipleWebsites()
                && !$this->websiteContext->isStoreWide()
                && $this->overrides->hasOwnValues($section, $this->websiteContext->getWebsiteId()));
    }

    /**
     * Whether the selected website must keep its own values of a section
     * on Settings: the automations of a website on another Smaily account
     * than the store-wide one (CON-190) or with per-language Smaily accounts
     * (mode A), whose workflows
     * come from those accounts (WizardStepSaver::sectionTarget()). Its
     * switch shows ticked and fixed.
     */
    public function isOwnRequired(string $section): bool
    {
        if ($section !== WebsiteOverrides::AUTOMATIONS
            || !$this->websiteContext->hasMultipleWebsites()
            || $this->websiteContext->isStoreWide()
        ) {
            return false;
        }
        $websiteId = $this->websiteContext->getWebsiteId();

        return $this->config->getMultilingualMode($websiteId) === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
            || $this->overrides->usesOtherAccount($websiteId);
    }

    /**
     * Whether Settings tells the merchant to pick the selected website's
     * workflows (CON-190): it uses another Smaily account than the
     * store-wide one, which starts only its own workflows, and it holds none
     * — it has just moved onto that account, or it held that account with
     * the store-wide automations before CON-190.
     */
    public function needsOwnWorkflows(): bool
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        if (!$this->overrides->usesOtherAccount($websiteId)) {
            return false;
        }
        $own = $this->overrides->ownValues(WebsiteOverrides::AUTOMATIONS)[$websiteId] ?? [];
        foreach (self::WORKFLOW_PATHS as $path) {
            if ((int)($own[$path] ?? 0) > 0) {
                return false;
            }
        }

        return (int)($own['mappings'] ?? 0) === 0;
    }

    /**
     * The websites that hold their own values of a section, for the initial
     * setup's step on a 2+-website install (CON-179): each with its name
     * and its own values as label/value lines (a password only as saved).
     * Empty elsewhere.
     *
     * @return array<int, array{websiteId: int, name: string, values: array<int, array{0: string, 1: string}>, required: bool}>
     */
    public function getWebsiteOverrides(string $section): array
    {
        if (!$this->websiteContext->isStoreWide() || !$this->websiteContext->hasMultipleWebsites()) {
            return [];
        }
        $names = $this->websiteContext->getWebsiteOptions();
        $list = [];
        foreach ($this->overrides->ownValues($section) as $websiteId => $values) {
            $lines = [];
            foreach ($values as $key => $value) {
                $lines[] = $this->describeOwnValue($key, $value);
            }
            $list[] = [
                'websiteId' => $websiteId,
                'name' => $names[$websiteId] ?? (string)$websiteId,
                'values' => $lines,
                // A website on another Smaily account keeps its own
                // automations (CON-190).
                'required' => $section === WebsiteOverrides::AUTOMATIONS
                    && $this->overrides->usesOtherAccount($websiteId),
            ];
        }

        return $list;
    }

    /**
     * Every website's name, in website order — the websites a store-wide
     * Contacts save starts contact sync on.
     *
     * @return string[]
     */
    public function getWebsiteNames(): array
    {
        return array_values($this->websiteContext->getWebsiteOptions());
    }

    /**
     * The optional contact fields with their labels (the Contacts panel's
     * checkboxes and the initial setup's website list).
     *
     * @return array<string, \Magento\Framework\Phrase>
     */
    public static function syncFieldLabels(): array
    {
        return [
            'first_name' => __('First name'),
            'last_name' => __('Last name'),
            'prefix' => __('Name prefix'),
            'user_phone' => __('Phone'),
            'user_gender' => __('Gender'),
            'birthday' => __('Date of birth'),
            'customer_id' => __('Customer ID'),
            'customer_group' => __('Customer group'),
            'subscription_type' => __('Subscription type'),
        ];
    }

    /**
     * One own value of a website as a label and a value, in the admin's
     * words; a value without a label names itself (a mode).
     *
     * @return array{0: string, 1: string}
     */
    private function describeOwnValue(string $key, string $value): array
    {
        $flag = static fn (string $flagValue): string => (string)($flagValue === '1' ? __('On') : __('Off'));
        $workflow = static fn (string $id): string => (int)$id > 0 ? '#' . (int)$id : '—';
        $withWorkflow = static fn (string $trigger): string => $trigger . ' · ' . __('Smaily Workflow');
        $modes = [
            'single' => __('Single language'),
            'a' => __('Per-language Smaily accounts'),
            'b' => __('One account, per-language workflows'),
            'c' => __('One workflow branching by language'),
        ];
        $syncModes = [
            SyncMode::MODE_CONSENT => __('Subscribers only (consent)'),
            SyncMode::MODE_LEGITIMATE_INTEREST => __('All customers (legitimate interest)'),
            SyncMode::MODE_CHECKOUT_OPTIN => __('Checkout opt-in only'),
        ];
        $fieldLabels = self::syncFieldLabels();

        $line = match ($key) {
            Config::XML_PATH_SUBDOMAIN => [__('Subdomain'), $value],
            Config::XML_PATH_USERNAME => [__('API username'), $value],
            Config::XML_PATH_PASSWORD => [__('API password'), '••••••••'],
            Config::XML_PATH_MULTILINGUAL_MODE => ['', (string)($modes[$value] ?? $value)],
            Config::XML_PATH_FALLBACK_LANGUAGE => [__('Default fallback account'), strtoupper($value)],
            Config::XML_PATH_STOREFRONT_URL => [__('Storefront URL'), $value],
            Config::XML_PATH_SYNC_ENABLED => [__('Sync contacts to Smaily'), $flag($value)],
            Config::XML_PATH_SYNC_MODE => ['', (string)($syncModes[$value] ?? $value)],
            Config::XML_PATH_SYNC_FIELDS => [
                __('Extra fields to sync with each contact'),
                implode(', ', array_map(
                    static fn (string $field): string => (string)($fieldLabels[$field] ?? $field),
                    array_filter(array_map('trim', explode(',', $value)))
                )) ?: '—',
            ],
            Config::XML_PATH_INCLUDE_GUESTS => [__('Include guest order emails'), $flag($value)],
            Config::XML_PATH_CHECKOUT_OPTIN_ENABLED => [__('Show a newsletter checkbox at checkout'), $flag($value)],
            Config::XML_PATH_SUPPRESS_OPTIN_EMAILS => [
                __('Let Smaily send the opt-in confirmation emails (suppresses Magento\'s own)'),
                $flag($value),
            ],
            Config::XML_PATH_WELCOME_ENABLED => [__('Welcome'), $flag($value)],
            Config::XML_PATH_WELCOME_WORKFLOW => [$withWorkflow((string)__('Welcome')), $workflow($value)],
            Config::XML_PATH_FIRST_ORDER_ENABLED => [__('First order'), $flag($value)],
            Config::XML_PATH_FIRST_ORDER_WORKFLOW => [$withWorkflow((string)__('First order')), $workflow($value)],
            Config::XML_PATH_ABANDONED_ENABLED => [__('Abandoned cart'), $flag($value)],
            Config::XML_PATH_ABANDONED_WORKFLOW => [$withWorkflow((string)__('Abandoned cart')), $workflow($value)],
            Config::XML_PATH_ABANDONED_CUTOFF => [__('Abandoned cart') . ' · ' . __('Wait (minutes)'), $value],
            'mappings' => [__('Per-language workflows'), $value],
            default => [$key, $value],
        };

        return [(string)$line[0], (string)$line[1]];
    }

    /**
     * More than one real website on this install? The selector and the
     * website exceptions only ever render when true; single-website
     * installs never see them.
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
     * The initial setup's calls carry website 0: the whole store (CON-179).
     *
     * @return array{_query: array{website: int}}
     */
    public function getWebsiteQuery(): array
    {
        return ['_query' => ['website' => $this->websiteContext->isStoreWide()
            ? WebsiteContext::STORE_WIDE
            : $this->websiteContext->getWebsiteId()]];
    }

    /**
     * Whether Smaily accepted the saved credentials at the last real check
     * (PRO-3560) — what the Connection status shows. Rendered into the page
     * so no other state shows before the script runs (PRO-3570). The
     * account is the one saved for the website — the single account, or
     * with per-language accounts the default fallback account — the one a
     * connection save checks, so a reload shows what the save showed
     * (PRO-3719).
     */
    public function isSmailyVerified(): bool
    {
        return $this->verifiedCredentials->isWebsiteVerified($this->websiteContext->getWebsiteId());
    }

    /**
     * The Smaily subdomain saved for the selected website (the account the
     * connection status describes).
     */
    public function getSavedSubdomain(): string
    {
        return $this->config->getWebsiteSubdomain($this->websiteContext->getWebsiteId());
    }

    /**
     * The Smaily API username saved for the selected website.
     */
    public function getSavedUsername(): string
    {
        return $this->config->getWebsiteUsername($this->websiteContext->getWebsiteId());
    }

    /**
     * The saved storefront address of the selected website ('' when none,
     * PRO-3660).
     */
    public function getSavedStorefrontUrl(): string
    {
        return $this->config->getStorefrontUrl($this->websiteContext->getStoreId());
    }

    /**
     * Whether the orders of the last 30 days came only through the API, so
     * the Storefront URL field opens by itself (PRO-3660).
     */
    public function isApiOnlyStore(): bool
    {
        return $this->orderOrigin->isApiOnly();
    }

    /**
     * Whether the "Using a separate storefront?" disclosure is drawn open: a
     * storefront address is saved, or the store is API-only (PRO-3660).
     */
    public function isStorefrontDisclosureOpen(): bool
    {
        return $this->getSavedStorefrontUrl() !== '' || $this->isApiOnlyStore();
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
            'planBlocked' => $this->verifiedCredentials->isWebsitePlanBlocked($websiteId),
            'setupCompleted' => $this->isSetupCompleted(),
            'storeId' => $storeId,
            'connection' => [
                'subdomain' => $this->config->getSubdomain($storeId),
                'username' => $this->config->getUsername($storeId),
                'hasPassword' => $this->config->getPassword($storeId) !== '',
                'multilingualMode' => $this->getMultilingualMode(),
            ],
            // The account saved for the website — the single account, or the
            // default fallback account in per-language accounts mode. An
            // empty password keeps its password only for this account
            // (PRO-3699, PRO-3718).
            'websiteAccount' => [
                'subdomain' => $this->config->getWebsiteSubdomain($websiteId),
                'username' => $this->config->getWebsiteUsername($websiteId),
            ],
            'multilingual' => [
                'languages' => $this->getDetectedLanguages(),
                'fallbackLanguage' => $this->config->getFallbackLanguage($websiteId),
            ],
            'subscribers' => [
                'syncEnabled' => $this->config->isSyncEnabled($websiteId),
                // Whether contacts sync: the switch and the saved Contacts
                // settings (PRO-4036). The contacts import card follows it
                // (PRO-4037).
                'syncActive' => $this->config->isContactSyncActive($websiteId),
                'syncMode' => $this->mode->mode($websiteId),
                'syncFields' => $this->config->getSyncFields($websiteId),
                'includeGuests' => $this->config->includeGuests($websiteId),
                'checkoutOptin' => $this->config->isCheckoutOptinEnabled($websiteId),
                'suppressOptinEmails' => $this->config->suppressOptinEmails($websiteId),
            ],
            'automations' => $this->ownWorkflows($websiteId, [
                'welcomeEnabled' => $this->config->isWelcomeEnabled($websiteId),
                'welcomeWorkflow' => (string)($this->config->getWelcomeWorkflow($websiteId) ?: ''),
                'firstOrderEnabled' => $this->config->isFirstOrderEnabled($websiteId),
                'firstOrderWorkflow' => (string)($this->config->getFirstOrderWorkflow($websiteId) ?: ''),
                'abandonedEnabled' => $this->config->isAbandonedCartEnabled($websiteId),
                'abandonedWorkflow' => (string)($this->config->getAbandonedCartWorkflow($websiteId) ?: ''),
                'abandonedCutoff' => $this->config->getAbandonedCutoffMinutes($websiteId),
            ]),
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
        ]);
    }

    /**
     * The automations' prefill with, for a website that uses its own Smaily
     * account, only the workflows saved at its website scope (CON-190): a
     * store-wide workflow it inherits was picked from another account, and
     * the Router does not start it there.
     *
     * @param array<string, mixed> $automations
     * @return array<string, mixed>
     */
    private function ownWorkflows(int $websiteId, array $automations): array
    {
        if (!$this->overrides->usesOtherAccount($websiteId)) {
            return $automations;
        }
        foreach (self::WORKFLOW_PATHS as $key => $path) {
            $own = (int)$this->overrides->ownValue(WebsiteOverrides::AUTOMATIONS, $websiteId, $path);
            $automations[$key] = $own > 0 ? (string)$own : '';
        }

        return $automations;
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
     * only, which renders the switch when it is off (PRO-4010): Settings
     * carries the switch itself, so panel/panels-js.phtml owns the import
     * control's state there, from `subscribers.syncEnabled` and
     * `subscribers.syncActive` above.
     */
    public function isSyncEnabled(): bool
    {
        return $this->config->isSyncEnabled($this->websiteContext->getWebsiteId());
    }

    /**
     * Whether the selected website's contacts already sync to Smaily (its
     * Contacts settings were saved, or it synced before the update,
     * PRO-4036). Read by the wizard, whose Contacts step says otherwise
     * that contacts start syncing with its save.
     */
    public function isContactSyncActive(): bool
    {
        return $this->config->isContactSyncActive($this->websiteContext->getWebsiteId());
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
        // The initial setup on a 2+-website install imports from every
        // website (CON-179).
        $websiteId = $this->websiteContext->getWebsiteId();
        $websiteIds = $websiteId === WebsiteContext::STORE_WIDE
            ? array_keys($this->websiteContext->getWebsiteOptions())
            : [$websiteId];
        $counts = [];
        foreach ([
            SyncMode::MODE_CONSENT,
            SyncMode::MODE_LEGITIMATE_INTEREST,
            SyncMode::MODE_CHECKOUT_OPTIN,
        ] as $mode) {
            $counts[$mode] = 0;
            foreach ($websiteIds as $countedId) {
                $counts[$mode] += $this->contactAudience->count($countedId, $mode);
            }
        }

        return $counts;
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
        $mode = $this->config->getMultilingualMode($this->websiteContext->getWebsiteId())
            ?: MultilingualMode::MODE_SINGLE;

        // Per-language accounts are a website's own on a 2+-website install
        // (WizardStepSaver::saveConnect()).
        return $mode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
            && $this->websiteContext->getWebsiteId() === WebsiteContext::STORE_WIDE
            ? MultilingualMode::MODE_SINGLE
            : $mode;
    }

    /**
     * More than one distinct storefront language configured?
     */
    public function isMultilingual(): bool
    {
        return count($this->getDetectedLanguages()) > 1;
    }

    /**
     * The selected website's languages; the initial setup on a 2+-website
     * install covers every website's (CON-179).
     *
     * @return string[]
     */
    public function getDetectedLanguages(): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();

        return $websiteId === WebsiteContext::STORE_WIDE
            ? $this->accountResolver->detectedLanguagesOfAllWebsites()
            : $this->accountResolver->detectedLanguages($websiteId);
    }

    /**
     * The language whose account is the selected website's mode-A default
     * fallback ('' = none picked yet).
     */
    public function getFallbackLanguage(): string
    {
        return $this->config->getFallbackLanguage($this->websiteContext->getWebsiteId());
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
        if ($websiteId === WebsiteContext::STORE_WIDE) {
            return $accounts;
        }
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
     * The transactional-email account as the selected website uses it
     * (PRO-4062): its own, else the one saved for all websites, which
     * `shared` holds as well (the fields show it when the website does not
     * use its own), and the website's "Send transactional emails" switch
     * (`enabled`, PRO-4065). Passwords are never returned, only whether one
     * is saved.
     *
     * @return array{enabled: bool, subdomain: string, username: string, hasPassword: bool, websiteAccount: bool,
     *     verified: bool, shared: array{subdomain: string, username: string, hasPassword: bool}}
     */
    public function getTransactionalAccount(): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();

        return [
            'enabled' => $this->config->isTransactionalEnabled($websiteId),
            'subdomain' => $this->config->getTransactionalSubdomain($websiteId),
            'username' => $this->config->getTransactionalUsername($websiteId),
            'hasPassword' => $this->config->getTransactionalPassword($websiteId) !== '',
            'websiteAccount' => $this->config->hasTransactionalWebsiteAccount($websiteId),
            // Smaily accepted it at the last real check (PRO-3560): the gate
            // of the trigger settings on the Automations tab.
            'verified' => $this->verifiedCredentials->isTransactionalVerified($websiteId),
            'shared' => [
                'subdomain' => $this->config->getTransactionalSubdomain(null),
                'username' => $this->config->getTransactionalUsername(null),
                'hasPassword' => $this->config->getTransactionalPassword(null) !== '',
            ],
        ];
    }

    /**
     * The selected website's Customer details switch of the transactional
     * emails (PRO-4065, off by default).
     */
    public function isTransactionalPersonalDataEnabled(): bool
    {
        return $this->config->isTransactionalPersonalDataEnabled($this->websiteContext->getWebsiteId());
    }

    /**
     * The selected website's transactional-email triggers: each one's switch
     * and its saved workflows by store language ('default' on a website with
     * one language).
     *
     * @return array<string, array{enabled: bool,
     *     workflows: array<string, array{workflowId: int, isDefaultFallback: bool}>}>
     */
    public function getTransactionalTriggers(): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $triggers = [
            Trigger::ORDER_CONFIRMATION => [
                'enabled' => $this->config->isOrderConfirmationEnabled($websiteId),
                'workflows' => [],
            ],
            Trigger::SHIPMENT_CONFIRMATION => [
                'enabled' => $this->config->isShipmentConfirmationEnabled($websiteId),
                'workflows' => [],
            ],
        ];
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['eq' => $websiteId])
            ->addFieldToFilter('trigger_type', ['in' => Trigger::TRANSACTIONAL])
            ->addFieldToFilter('account_key', Mapping::ACCOUNT_TRANSACTIONAL);
        /** @var Mapping $mapping */
        foreach ($collection as $mapping) {
            $trigger = (string)$mapping->getData('trigger_type');
            $triggers[$trigger]['workflows'][(string)$mapping->getData('language')] = [
                'workflowId' => $mapping->getWorkflowId(),
                'isDefaultFallback' => (bool)$mapping->getData('is_default_fallback'),
            ];
        }

        return $triggers;
    }

    /**
     * Saved workflow-mapping rows for the target website, keyed
     * "trigger|language" for template prefill. Legacy website_id=0 rows
     * (pre-Phase-3 saves, the 2.8.x migration's default-scope seeding) are
     * read as a fallback for a key the target website hasn't saved its own
     * row for yet — the same website-beats-global preference
     * `Model\Automation\Router` applies at dispatch time. Only the rows of
     * the given mode's accounts (mode A or B) are read, as the Router reads
     * them (CON-184): the editor shows each mode its own workflows, and a
     * mode-B save never posts another account's workflow id. A website that
     * uses another Smaily account reads only its own rows, as the Router
     * does (CON-190).
     *
     * @return array<string, array{workflowId: int, isDefaultFallback: bool}>
     */
    public function getSavedMappings(string $mode): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $ownAccount = $this->overrides->usesOtherAccount($websiteId);
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['in' => $ownAccount ? [$websiteId] : [$websiteId, 0]])
            ->addFieldToFilter('trigger_type', ['in' => Trigger::ALL])
            ->addModeFilter($mode)
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
