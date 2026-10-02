<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Config\Source\SyncFields;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Persists wizard steps into the SAME system config paths declared by
 * `etc/adminhtml/system.xml` (kept for encrypted backend models and CLI
 * `config:set`/`config:show`; the native `Stores > Configuration > Smaily`
 * section itself is hidden) — one source of truth, the wizard is just a
 * guided view over it.
 *
 * Website-scoped fields (connection credentials, subscriber sync toggles,
 * automation toggles — RFC_MULTI_WEBSITE.md §1) write at the target
 * website's scope, defaulting to the installation's default website when no
 * website is specified — unchanged behaviour for a single-website install,
 * since Config's readers already resolve website scope. The automation
 * mapping table saves at that same target website scope (§6, Phase 3). The
 * setup-completed flag is also website-scoped (§2, Phase 2) so each
 * website's own onboarding is tracked independently. Fields outside that
 * list (Intelligence, RSS) are untouched in this phase — they stay at
 * default scope.
 */
class WizardStepSaver
{
    public const XML_PATH_SETUP_COMPLETED = 'smaily_connect/internal/setup_completed';

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly TypeListInterface $cacheTypeList,
        private readonly SubdomainNormalizer $normalizer,
        private readonly AccountResolver $accountResolver,
        private readonly Config $config,
        private readonly WebsiteContext $websiteContext,
        private readonly StoreManagerInterface $storeManager,
        private readonly MappingSaver $mappingSaver,
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly ConfigRowNormalizer $rowNormalizer,
        private readonly SmailyClientFactory $smailyClientFactory
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function save(string $step, array $data): array
    {
        $websiteId = $this->websiteContext->getWebsiteId();
        $errors = match ($step) {
            'connect' => $this->saveConnect($data, $websiteId),
            'subscribers' => $this->saveSubscribers($data, $websiteId),
            'automations' => $this->saveAutomations($data, $websiteId),
            'intelligence' => $this->saveIntelligence($data),
            'rss' => $this->saveRss($data),
            'finish' => $this->saveFinish($websiteId),
            default => [['field' => 'step', 'message' => (string)__('Unknown wizard step "%1".', $step)]],
        };

        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveConnect(array $data, int $websiteId): array
    {
        $subdomain = $this->normalizer->normalize((string)($data['subdomain'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($subdomain === '' || $username === '') {
            return [['field' => 'subdomain', 'message' => (string)__('Subdomain and username are required.')]];
        }

        $this->configWriter->save(Config::XML_PATH_SUBDOMAIN, $subdomain, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        $this->configWriter->save(Config::XML_PATH_USERNAME, $username, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        if ($password !== '' && preg_match('/^\*+$/', $password) !== 1) {
            $this->configWriter->save(
                Config::XML_PATH_PASSWORD,
                $this->encryptor->encrypt($password),
                ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );
        }

        $previousMode = $this->config->getMultilingualMode($websiteId) ?: MultilingualMode::MODE_SINGLE;
        $mode = strtolower((string)($data['multilingual_mode'] ?? 'single'));
        if (in_array($mode, ['single', 'a', 'b', 'c'], true)) {
            $this->configWriter->save(
                Config::XML_PATH_MULTILINGUAL_MODE,
                $mode,
                ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );
        } else {
            $mode = $previousMode;
        }

        // Mode A: per-language accounts land as store-view scoped credentials
        // on every store view of this website speaking that language.
        foreach ((array)($data['accounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }
            $language = (string)($account['language'] ?? '');
            $accountSubdomain = $this->normalizer->normalize((string)($account['subdomain'] ?? ''));
            $accountUsername = trim((string)($account['username'] ?? ''));
            $accountPassword = (string)($account['password'] ?? '');
            if ($language === '' || $accountSubdomain === '' || $accountUsername === '') {
                continue;
            }
            foreach ($this->accountResolver->storeIdsForAccountKey($language, $websiteId) as $storeId) {
                $this->configWriter->save(
                    Config::XML_PATH_SUBDOMAIN,
                    $accountSubdomain,
                    ScopeInterface::SCOPE_STORES,
                    $storeId
                );
                $this->configWriter->save(
                    Config::XML_PATH_USERNAME,
                    $accountUsername,
                    ScopeInterface::SCOPE_STORES,
                    $storeId
                );
                if ($accountPassword !== '' && preg_match('/^\*+$/', $accountPassword) !== 1) {
                    $this->configWriter->save(
                        Config::XML_PATH_PASSWORD,
                        $this->encryptor->encrypt($accountPassword),
                        ScopeInterface::SCOPE_STORES,
                        $storeId
                    );
                }
            }
        }

        // The default-fallback account (mode A): its credentials are also
        // posted as the top-level subdomain/username/password, so the default
        // scope IS the fallback account; the language is remembered for the
        // admin UI's fallback picker.
        $fallbackLanguage = strtolower(trim((string)($data['fallback_language'] ?? '')));
        if ($fallbackLanguage !== '' && preg_match('/^[a-z]{2,3}$/', $fallbackLanguage) === 1) {
            $this->configWriter->save(Config::XML_PATH_FALLBACK_LANGUAGE, $fallbackLanguage);
        }

        // Leaving mode A is destructive by design (the UI confirms first):
        // the per-store-view credential overrides written for this website's
        // per-language accounts are removed, so every store view of this
        // website follows the single account again. Mapping rows are kept —
        // the Router ignores them outside modes a/b.
        if ($previousMode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
            && $mode !== MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
        ) {
            $website = $this->storeManager->getWebsite($websiteId);
            if ($website instanceof Website) {
                foreach ($website->getStores() as $store) {
                    foreach (
                        [Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD] as $path
                    ) {
                        $this->configWriter->delete($path, ScopeInterface::SCOPE_STORES, (int)$store->getId());
                    }
                }
            }
        }

        $this->checkCredentials($subdomain, $username, $password);

        return [];
    }

    /**
     * Saving the connection is a real check (PRO-3560): Smaily is asked once
     * whether it accepts the credentials just saved, and SmailyClient
     * remembers the answer for the Dashboard and the Connection status. The
     * save never depends on it — a refusal or an unreachable Smaily is shown
     * as "Not connected", not as a failed save.
     */
    private function checkCredentials(string $subdomain, string $username, string $password): void
    {
        if ($password === '' || preg_match('/^\*+$/', $password) === 1) {
            // Kept password: the stored one, still unchanged in this request.
            $password = $this->config->getPassword($this->websiteContext->getStoreId());
        }
        if ($password === '') {
            return;
        }

        try {
            $this->smailyClientFactory->create([
                'subdomain' => $subdomain,
                'username' => $username,
                'password' => $password,
            ])->validateCredentials();
        } catch (SmailyClientException) {
            // Remembered (or not) by SmailyClient; the save stands either way.
            return;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveSubscribers(array $data, int $websiteId): array
    {
        $this->saveFlag(Config::XML_PATH_SYNC_ENABLED, $data, 'sync_enabled', $websiteId);
        $this->saveFlag(Config::XML_PATH_INCLUDE_GUESTS, $data, 'include_guests', $websiteId);
        $this->saveFlag(Config::XML_PATH_AUTOMATION_FORCE_OPT_IN, $data, 'automation_force_opt_in', $websiteId);
        $this->saveFlag(Config::XML_PATH_CHECKOUT_OPTIN_ENABLED, $data, 'checkout_optin_enabled', $websiteId);
        $this->saveFlag(Config::XML_PATH_SUPPRESS_OPTIN_EMAILS, $data, 'suppress_optin_emails', $websiteId);

        $mode = (string)($data['sync_mode'] ?? '');
        if (in_array($mode, [
            SyncMode::MODE_CONSENT,
            SyncMode::MODE_LEGITIMATE_INTEREST,
            SyncMode::MODE_CHECKOUT_OPTIN,
        ], true)) {
            $this->configWriter->save(Config::XML_PATH_SYNC_MODE, $mode, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        }

        if (isset($data['sync_fields']) && is_array($data['sync_fields'])) {
            $fields = array_values(array_intersect(
                SyncFields::SUPPORTED_FIELDS,
                array_map('strval', $data['sync_fields'])
            ));
            $this->configWriter->save(
                Config::XML_PATH_SYNC_FIELDS,
                implode(',', $fields),
                ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveAutomations(array $data, int $websiteId): array
    {
        $this->saveFlag(Config::XML_PATH_WELCOME_ENABLED, $data, 'welcome_enabled', $websiteId);
        $this->saveFlag(Config::XML_PATH_FIRST_ORDER_ENABLED, $data, 'first_order_enabled', $websiteId);
        $this->saveFlag(Config::XML_PATH_ABANDONED_ENABLED, $data, 'abandoned_enabled', $websiteId);

        // A saved workflow id that is missing from the freshly loaded Smaily
        // list was never offered in the select, so an empty post is not a
        // deliberate clear — the stored binding is kept rather than dropped
        // (PRO-1286, same rule as the engine-automations single mode). Only
        // resolved lazily below, when a workflow key is actually posted.
        $availableWorkflowIds = null;
        foreach ([
            'welcome_workflow' => [Config::XML_PATH_WELCOME_WORKFLOW, $this->config->getWelcomeWorkflow($websiteId)],
            'first_order_workflow' =>
                [Config::XML_PATH_FIRST_ORDER_WORKFLOW, $this->config->getFirstOrderWorkflow($websiteId)],
            'abandoned_workflow' =>
                [Config::XML_PATH_ABANDONED_WORKFLOW, $this->config->getAbandonedCartWorkflow($websiteId)],
        ] as $key => [$path, $savedWorkflow]) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $postedId = ($id = (int)$data[$key]) > 0 ? (string)$id : '';
            $savedId = $savedWorkflow > 0 ? (string)$savedWorkflow : '';
            $availableWorkflowIds ??= $this->workflowIdsForStore(null);
            if ($this->rowNormalizer->isMissingFromList($postedId, $savedId, $availableWorkflowIds)) {
                // Missing-from-list preserve: leave the stored value untouched.
                continue;
            }
            $this->configWriter->save(
                $path,
                $postedId === '' ? '0' : $postedId,
                ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );
        }

        if (array_key_exists('abandoned_cutoff', $data)) {
            $this->configWriter->save(
                Config::XML_PATH_ABANDONED_CUTOFF,
                (string)max(Config::MIN_ABANDONED_CUTOFF_MINUTES, min(1440, (int)$data['abandoned_cutoff'])),
                ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );
        }

        // Per-language workflow mappings (multilingual modes a/b). The panel
        // sends the full desired state, so absent selections delete their
        // rows; single/c saves omit the key and leave the table untouched.
        // The per-account available-workflow lists let the saver preserve a
        // saved mapping row whose id is missing from its account's live list
        // instead of dropping it on the full sync (PRO-1286). The mapping
        // table now saves at the real target website scope
        // (RFC_MULTI_WEBSITE.md §6, Phase 3) — the Router already prefers a
        // website-specific row over a legacy website_id=0 row, so a
        // single-website install keeps resolving its pre-existing rows
        // unchanged until this website's own row is saved.
        if (isset($data['mappings']) && is_array($data['mappings'])) {
            return $this->mappingSaver->save(
                $data['mappings'],
                $websiteId,
                $this->availableWorkflowIdsByAccount($websiteId)
            );
        }

        return [];
    }

    /**
     * Available workflow ids per mapping account key: 'default' (the shared /
     * mode-B account) plus each language detected on the given website (mode
     * A, where a language's rows route through that language's own Smaily
     * account). An account whose list cannot be loaded maps to an empty list,
     * which the saver reads as "unknown" and preserves.
     *
     * @return array<string, array<int, string>>
     */
    private function availableWorkflowIdsByAccount(int $websiteId): array
    {
        $byAccount = [Mapping::ACCOUNT_DEFAULT => $this->workflowIdsForStore(null)];
        foreach ($this->accountResolver->languageStoreIds($websiteId) as $language => $storeId) {
            $byAccount[$language] = $this->workflowIdsForStore($storeId);
        }

        return $byAccount;
    }

    /**
     * Workflow ids the Smaily account bound to the given store scope can list,
     * as strings. An empty array means the list is unavailable (credentials
     * missing or the listing failed) — the caller then keeps every saved id.
     *
     * @return array<int, string>
     */
    private function workflowIdsForStore(?int $storeId): array
    {
        try {
            $workflows = $this->smailyClientProvider->forStore($storeId)->getAutomationWorkflows();
        } catch (SmailyClientException) {
            return [];
        }

        return array_map(static fn (array $workflow): string => (string)$workflow['id'], $workflows);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveIntelligence(array $data): array
    {
        $this->saveFlag(EngineSettings::XML_PATH_BROWSE_TRACKING, $data, 'browse_tracking');

        return [];
    }

    /**
     * Settings-page RSS tab (the wizard has no RSS step of its own).
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveRss(array $data): array
    {
        $this->saveFlag(Config::XML_PATH_RSS_ENABLED, $data, 'rss_enabled');

        return [];
    }

    /**
     * @return array<int, array{field: string, message: string}>
     */
    private function saveFinish(int $websiteId): array
    {
        $this->configWriter->save(
            self::XML_PATH_SETUP_COMPLETED,
            '1',
            ScopeInterface::SCOPE_WEBSITES,
            $websiteId
        );

        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveFlag(string $path, array $data, string $key, ?int $websiteId = null): void
    {
        if (!array_key_exists($key, $data)) {
            return;
        }
        $value = $data[$key] ? '1' : '0';
        if ($websiteId === null) {
            $this->configWriter->save($path, $value);
        } else {
            $this->configWriter->save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        }
    }
}
