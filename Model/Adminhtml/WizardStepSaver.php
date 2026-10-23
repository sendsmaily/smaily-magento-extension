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
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\Exception\InvalidSubdomainException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Config\Source\SyncFields;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\SmailyUrl;
use Smaily\Connect\Model\StorefrontUrl;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Persists wizard steps into the SAME system config paths declared by
 * `etc/adminhtml/system.xml` (kept for encrypted backend models and CLI
 * `config:set`/`config:show`; the native `Stores > Configuration > Smaily`
 * section itself is hidden) — one source of truth, the wizard is just a
 * guided view over it.
 *
 * The extension is set up once for the whole store (CON-179): the Smaily
 * account, the Contacts settings and the automations (WebsiteOverrides'
 * sections) save at the default scope, and a website differs only where it
 * holds its own values. Where a section's save writes (sectionTarget()):
 * - The initial setup (a store-wide request) writes the default scope; on a
 *   2+-website install a website listed with its own values keeps them
 *   unless the post names it in `use_store_wide`.
 * - Settings on a 2+-website install, for its selected website: `own` true
 *   writes the website's own values (its scope; the mapping rows under its
 *   id); otherwise a website with own values gets them removed and nothing
 *   else is written (it returns to the store-wide values), and a website
 *   without writes the store-wide values it shows.
 * - A single-website install writes the default scope and removes the
 *   website's own values: the store and its one website are one thing.
 * - Per-language Smaily accounts (multilingual mode A) are always a
 *   website's own (owner decision 2026-10-22): they live on its store
 *   views, and its per-language workflow rows name those accounts. A
 *   Smaily account saved in mode A, and the automations of a website in
 *   mode A, are written at the website's scope — also by the initial setup
 *   of a single-website install — so a website added later does not
 *   inherit them. A store-wide save on a 2+-website install does not take
 *   mode A (saveConnect()).
 * - A website of a 2+-website install that uses another Smaily account
 *   than the store-wide one (WebsiteOverrides::usesOtherAccount(): the
 *   account's identity decides, not the scope its login is saved at)
 *   always has its own automations (CON-190, the same rule as mode A): its
 *   workflows come from that account. Its automations are written at its
 *   scope, and a store-wide save does not remove them. A connection save
 *   that gives it another account than the store-wide one starts its
 *   automations from no workflow (its own automations are removed; the
 *   store-wide ones it then does not use). A website that returns from
 *   another account to the store-wide one returns to the store-wide
 *   automations as well. A website whose own login is the store-wide
 *   account keeps its automations either way.
 * Store-wide mapping rows are website 0's. The setup-completed flag is one
 * mark for the store (default scope). Intelligence and RSS are default
 * scope as before.
 */
class WizardStepSaver
{
    public const XML_PATH_SETUP_COMPLETED = 'smaily_connect/internal/setup_completed';

    /**
     * The post key prefix of each automation's discount code settings
     * (CON-198): `<prefix>_coupon_enabled`, `_coupon_rule`, `_coupon_days`.
     */
    private const COUPON_KEYS = [
        Trigger::WELCOME => 'welcome',
        Trigger::FIRST_ORDER => 'first_order',
        Trigger::ABANDONED_CART => 'abandoned',
    ];

    /** Whether Smaily accepted the credentials the last connection save checked (PRO-3570). */
    private bool $connectionAccepted = false;

    /** Whether the last connection save changed the storefront address (PRO-3660). */
    private bool $storefrontUrlChanged = false;

    /** Whether the last save returned the website to the store-wide values (CON-179). */
    private bool $returnedToStoreWide = false;

    /** Whether the last save moved the website onto another Smaily account than the store-wide one (CON-190). */
    private bool $ownAccountStarted = false;

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
        private readonly CredentialCheck $credentialCheck,
        private readonly SetupNotice $setupNotice,
        private readonly TransactionalSettingsSaver $transactionalSaver,
        private readonly WebsiteOverrides $overrides
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function save(string $step, array $data): array
    {
        $this->returnedToStoreWide = false;
        $this->ownAccountStarted = false;
        // The scope the section's saved values are read from: the selected
        // website, or for the initial setup the store (WebsiteContext).
        $websiteId = $this->websiteContext->getWebsiteId();
        $errors = match ($step) {
            'connect' => $this->saveConnection($data, $websiteId),
            'subscribers' => $this->saveSection(
                WebsiteOverrides::SUBSCRIBERS,
                $data,
                $websiteId,
                fn (?int $target): array => $this->saveSubscribers($data, $target)
            ),
            'automations' => $this->saveSection(
                WebsiteOverrides::AUTOMATIONS,
                $data,
                $websiteId,
                fn (?int $target): array => $this->saveAutomations($data, $websiteId, $target)
            ) ?: $this->saveTransactionalTriggers($data, $websiteId),
            'intelligence' => $this->saveIntelligence($data),
            'rss' => $this->saveRss($data),
            'finish' => $this->saveFinish(),
            default => [['field' => 'step', 'message' => (string)__('Unknown wizard step "%1".', $step)]],
        };

        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);

        return $errors;
    }

    /**
     * Whether the last save returned the selected website to the store-wide
     * values of a section (CON-179): it removed the website's own values
     * and wrote nothing else, so the page shows the store-wide values only
     * after a reload.
     */
    public function isReturnedToStoreWide(): bool
    {
        return $this->returnedToStoreWide;
    }

    /**
     * Whether the last save moved the selected website onto another Smaily
     * account than the store-wide one (CON-190): its automations now start
     * from no workflow, so the page shows them only after a reload.
     */
    public function isOwnAccountStarted(): bool
    {
        return $this->ownAccountStarted;
    }

    /**
     * Saves a section where sectionTarget() says, then removes the own
     * values of the websites it names.
     *
     * @param array<string, mixed> $data
     * @param callable(int|null): array<int, array{field: string, message: string}> $save
     * @return array<int, array{field: string, message: string}>
     */
    private function saveSection(string $section, array $data, int $websiteId, callable $save): array
    {
        [$target, $remove, $write] = $this->sectionTarget($section, $data, $websiteId);
        $multiple = $this->websiteContext->hasMultipleWebsites();
        // A Smaily account saved for one website of a 2+-website install
        // (CON-190): whether it used another account than the store-wide
        // one before this save.
        $ownAccountSave = $section === WebsiteOverrides::CONNECTION && $multiple && $write && $target !== null;
        $usedOtherAccount = $ownAccountSave && $this->overrides->usesOtherAccount((int)$target);
        $errors = $write ? $save($target) : [];
        if ($errors !== []) {
            return $errors;
        }
        foreach ($remove as $removeId) {
            // The workflows of a website on another Smaily account came from
            // that account: its own automations go with it (CON-190).
            $otherAccount = $section === WebsiteOverrides::CONNECTION && $multiple
                && $this->overrides->usesOtherAccount($removeId);
            // A website that set its own per-language accounts (mode A)
            // keeps them on its store views; they go with its own account.
            $this->overrides->removeOwnValues($section, $removeId, $multiple);
            if ($otherAccount) {
                $this->overrides->removeOwnValues(WebsiteOverrides::AUTOMATIONS, $removeId);
            }
        }
        // Now on another account than the store-wide one: its own
        // automations so far were picked from the store-wide account, so
        // they start from no workflow (CON-190).
        if ($ownAccountSave && !$usedOtherAccount && $this->overrides->usesOtherAccount((int)$target)) {
            $this->overrides->removeOwnValues(WebsiteOverrides::AUTOMATIONS, (int)$target);
            $this->ownAccountStarted = true;
        }
        $this->returnedToStoreWide = !$write;

        return [];
    }

    /**
     * Where a section's save writes: the website id whose scope gets the
     * values (null = the default scope, the whole store), the websites
     * whose own values of the section are removed, and whether the values
     * are written at all (not when a website returns to the store-wide
     * values) — see the class comment.
     *
     * @param array<string, mixed> $data
     * @return array{0: int|null, 1: int[], 2: bool}
     */
    private function sectionTarget(string $section, array $data, int $websiteId): array
    {
        $withOwn = array_keys($this->overrides->ownValues($section));
        $perLanguage = $this->isPerLanguageAccounts($section, $data, $websiteId);
        if (!$this->websiteContext->hasMultipleWebsites()) {
            return $perLanguage
                ? [$websiteId, [], true]
                : [null, array_values(array_intersect($withOwn, [$websiteId])), true];
        }
        if ($this->websiteContext->isStoreWide()) {
            $chosen = array_map('intval', is_array($data['use_store_wide'] ?? null) ? $data['use_store_wide'] : []);
            // A website on another Smaily account keeps its automations (CON-190).
            if ($section === WebsiteOverrides::AUTOMATIONS) {
                $chosen = array_filter($chosen, fn (int $id): bool => !$this->overrides->usesOtherAccount($id));
            }

            return [null, array_values(array_intersect($withOwn, $chosen)), true];
        }
        // A website in mode A, or on another Smaily account than the
        // store-wide one, keeps its own automations (CON-190); unticking its
        // own Smaily account still returns it (and so leaves mode A).
        if (!empty($data['own'])
            || ($section === WebsiteOverrides::AUTOMATIONS
                && ($perLanguage || $this->overrides->usesOtherAccount($websiteId)))
        ) {
            return [$websiteId, [], true];
        }
        if (in_array($websiteId, $withOwn, true)) {
            return [null, [$websiteId], false];
        }

        return $perLanguage ? [$websiteId, [], true] : [null, [], true];
    }

    /**
     * Whether the section's save belongs to a website's per-language Smaily
     * accounts (mode A), which are always the website's own: a Smaily
     * account posted in mode A, or the automations of a website saved in
     * mode A. Never for the whole store of a 2+-website install (website 0).
     *
     * @param array<string, mixed> $data
     */
    private function isPerLanguageAccounts(string $section, array $data, int $websiteId): bool
    {
        if ($websiteId === WebsiteContext::STORE_WIDE) {
            return false;
        }

        return match ($section) {
            WebsiteOverrides::CONNECTION => strtolower((string)($data['multilingual_mode'] ?? ''))
                === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS,
            WebsiteOverrides::AUTOMATIONS => $this->config->getMultilingualMode($websiteId)
                === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS,
            default => false,
        };
    }

    /**
     * Saves a value at a website's scope, or at the default scope (null).
     */
    private function write(string $path, string $value, ?int $websiteId): void
    {
        if ($websiteId === null) {
            $this->configWriter->save($path, $value);
        } else {
            $this->configWriter->save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        }
    }

    /**
     * Whether Smaily accepted the credentials that the last connection save
     * in this request checked — the answer the Connection status shows
     * without a reload (PRO-3570). Read from the check itself, because the
     * configuration cache can still hold the credentials from before the
     * save. False when the save made no check.
     */
    public function isConnectionAccepted(): bool
    {
        return $this->connectionAccepted;
    }

    /**
     * Whether Smaily accepted the transactional-email account that the last
     * connection save in this request checked (PRO-4062). False when the
     * save made no check, or the post carried no such account.
     */
    public function isTransactionalAccepted(): bool
    {
        return $this->transactionalSaver->isAccountAccepted();
    }

    /**
     * Whether the last connection save in this request stored a storefront
     * address other than the one saved before — the product links Campaign
     * Intelligence holds then need the catalog import again (PRO-3660).
     */
    public function isStorefrontUrlChanged(): bool
    {
        return $this->storefrontUrlChanged;
    }

    /**
     * The connection save: the Smaily account, and on Settings > Connection
     * the optional transactional-email account (`transactional`, PRO-4062),
     * checked before either is written.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveConnection(array $data, int $websiteId): array
    {
        $transactional = is_array($data['transactional'] ?? null) ? $data['transactional'] : null;
        if ($transactional !== null) {
            $errors = $this->transactionalSaver->accountErrors($transactional, $websiteId);
            if ($errors !== []) {
                return $errors;
            }
        }

        $errors = $this->saveSection(
            WebsiteOverrides::CONNECTION,
            $data,
            $websiteId,
            fn (?int $target): array => $this->saveConnect($data, $websiteId, $target)
        );
        if ($errors === [] && $transactional !== null) {
            $this->transactionalSaver->saveAccount($transactional, $websiteId);
        }

        return $errors;
    }

    /**
     * The transactional-email triggers of Settings > Automations
     * (`transactional`, PRO-4062); the initial setup posts none.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveTransactionalTriggers(array $data, int $websiteId): array
    {
        return is_array($data['transactional'] ?? null)
            ? $this->transactionalSaver->saveTriggers($data['transactional'], $websiteId)
            : [];
    }

    /**
     * The Smaily account, read from $websiteId's saved values (the account
     * the form was drawn with) and written at $target's scope (null = the
     * default scope). Per-language accounts (mode A) live on the store
     * views of one website, so on a 2+-website install they are a website's
     * own Smaily account: a store-wide save there does not take mode A.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveConnect(array $data, int $websiteId, ?int $target): array
    {
        $this->connectionAccepted = false;
        $this->storefrontUrlChanged = false;
        $subdomain = $this->normalizer->normalize((string)($data['subdomain'] ?? ''));
        $username = trim((string)($data['username'] ?? ''));
        // Every posted password is read once as a new password, or '' when
        // the post keeps the saved one (empty or the masked placeholder).
        $password = $this->newPassword((string)($data['password'] ?? ''));
        if (is_array($data['accounts'] ?? null)) {
            foreach ($data['accounts'] as $index => $account) {
                if (is_array($account)) {
                    $data['accounts'][$index]['password'] = $this->newPassword(
                        (string)($account['password'] ?? '')
                    );
                }
            }
        }

        // Each error names the field that caused it, so the form can mark
        // that field (PRO-3562); a per-language account's fields are
        // "accounts.<language>.<field>".
        $required = [];
        foreach (['subdomain' => $subdomain, 'username' => $username] as $field => $value) {
            if ($value === '') {
                $required[] = ['field' => $field, 'message' => (string)__('Subdomain and username are required.')];
            }
        }
        if ($required !== []) {
            return $required;
        }

        // Every subdomain in the post must be a plain one before anything is
        // saved or checked: a refused value never meets the stored password.
        // The per-language accounts come first: in mode A the top-level
        // credentials repeat the fallback account's, whose field is the one
        // the merchant sees.
        $subdomains = [];
        foreach ((array)($data['accounts'] ?? []) as $account) {
            if (is_array($account)) {
                $field = 'accounts.' . (string)($account['language'] ?? '') . '.subdomain';
                $subdomains[$field] = $this->normalizer->normalize((string)($account['subdomain'] ?? ''));
            }
        }
        $subdomains['subdomain'] = $subdomain;
        foreach ($subdomains as $field => $candidate) {
            if ($candidate !== '' && !SmailyUrl::isPlainSubdomain($candidate)) {
                return [['field' => $field, 'message' => (new InvalidSubdomainException())->getMessage()]];
            }
        }

        // The storefront address (PRO-3660; Settings > Connection and the
        // initial setup's Connect step, PRO-3802) is checked before anything
        // is saved as well; a post without the key leaves it as is.
        $storefrontUrl = null;
        if (array_key_exists('storefront_url', $data)) {
            $storefrontUrl = is_string($data['storefront_url'])
                ? StorefrontUrl::normalize($data['storefront_url'])
                : null;
            if ($storefrontUrl === null) {
                return [[
                    'field' => 'storefront_url',
                    'message' => (string)__('Enter the storefront\'s address only, starting with https:// — for example https://shop.example.com — without a path or a query.'),
                ]];
            }
        }

        $previousMode = $this->config->getMultilingualMode($websiteId) ?: MultilingualMode::MODE_SINGLE;
        $postedMode = strtolower((string)($data['multilingual_mode'] ?? 'single'));
        $modes = $target === null && $this->websiteContext->hasMultipleWebsites()
            ? ['single', 'b', 'c']
            : ['single', 'a', 'b', 'c'];
        $postedModeValid = in_array($postedMode, $modes, true);
        $mode = $postedModeValid ? $postedMode : $previousMode;
        if (!in_array($mode, $modes, true)) {
            $mode = MultilingualMode::MODE_SINGLE;
            $postedModeValid = true;
        }
        $fallbackLanguage = strtolower(trim((string)($data['fallback_language'] ?? '')));

        // The accounts the store views hold before anything below is saved.
        $heldAccounts = $mode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS && is_array($data['accounts'] ?? null)
            ? $this->heldAccounts($websiteId)
            : [];

        $errors = $this->changedAccountPasswordErrors(
            $data,
            $mode,
            $subdomain,
            $username,
            $password,
            $fallbackLanguage,
            $heldAccounts,
            $websiteId
        );
        if ($errors !== []) {
            return $errors;
        }

        $this->write(Config::XML_PATH_SUBDOMAIN, $subdomain, $target);
        $this->write(Config::XML_PATH_USERNAME, $username, $target);
        // An empty password keeps the saved one; a store-wide save writes it
        // at the default scope too, where a single-website install's
        // website held it before (its own values are removed after the save).
        $keptPassword = $password === '' && $target === null ? $this->config->getWebsitePassword($websiteId) : '';
        if ($password !== '' || $keptPassword !== '') {
            $this->write(
                Config::XML_PATH_PASSWORD,
                $this->encryptor->encrypt($password !== '' ? $password : $keptPassword),
                $target
            );
        }

        if ($postedModeValid) {
            $this->write(Config::XML_PATH_MULTILINGUAL_MODE, $mode, $target);
        }

        if ($mode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS && is_array($data['accounts'] ?? null)) {
            $this->savePerLanguageAccounts($data['accounts'], $heldAccounts);
        }

        // The default-fallback account (mode A): its credentials are also
        // posted as the top-level subdomain/username/password, so the
        // saved account IS the fallback account; the language is remembered
        // for the admin UI's fallback picker, where the account is saved
        // (PRO-3719).
        if ($fallbackLanguage !== '' && preg_match('/^[a-z]{2,3}$/', $fallbackLanguage) === 1) {
            $this->write(Config::XML_PATH_FALLBACK_LANGUAGE, $fallbackLanguage, $target);
        }

        // Leaving mode A is destructive by design (the UI confirms first):
        // the per-store-view credential overrides written for this website's
        // per-language accounts are removed, so every store view of this
        // website follows the single account again. The per-language
        // workflow rows are kept for a switch back to mode A; the Router and
        // the mapping editor read only the active mode's rows (mode B never
        // posts them through the single account,
        // Mapping\Collection::addModeFilter(), CON-184).
        if ($previousMode === MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
            && $mode !== MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS
            && $websiteId !== WebsiteContext::STORE_WIDE
        ) {
            $website = $this->storeManager->getWebsite($websiteId);
            if ($website instanceof Website) {
                foreach ($website->getStores() as $store) {
                    $this->deleteStoreViewCredentials((int)$store->getId());
                }
            }
        }

        if ($storefrontUrl !== null) {
            $savedStorefrontUrl = StorefrontUrl::normalize(
                $this->config->getStorefrontUrl($this->websiteContext->getStoreId())
            );
            $this->storefrontUrlChanged = $storefrontUrl !== $savedStorefrontUrl;
            $this->write(Config::XML_PATH_STOREFRONT_URL, $storefrontUrl, $target);
        }

        $this->checkCredentials($subdomain, $username, $password, $websiteId);

        return [];
    }

    /**
     * A changed account needs its own password (PRO-3690). An empty or
     * masked password keeps the saved one only for the account the form
     * was drawn with: the subdomain (in any case) and username that the
     * store view behind it holds before the save. In mode A each
     * per-language block is compared with the store view it was drawn
     * from (`WizardData::getMultilingualAccounts`); a block without both
     * a subdomain and a username saves no account and needs none. In the
     * other modes the single account is compared with the account saved at
     * website scope, the scope it is written to and whose password an empty
     * one keeps — not with the website's store view the panel draws it
     * from, which after mode A can still hold a per-language account
     * (PRO-3699). In mode A the top-level credentials are the default
     * fallback account's, saved at website scope too: a fallback account
     * other than the one saved there needs its password as well, asked for
     * on the fallback block's password field (PRO-3718).
     *
     * Each password is the post's new password, or '' when it keeps the
     * saved one. A block's store view is the first store view of the website
     * with the block's language, in the website's store view order, taken
     * from the accounts the store views held before the save.
     *
     * @param array<string, mixed> $data
     * @param array<int, array{language: string, account: string, password: string}> $heldAccounts
     * @return array<int, array{field: string, message: string}>
     */
    private function changedAccountPasswordErrors(
        array $data,
        string $mode,
        string $subdomain,
        string $username,
        string $password,
        string $fallbackLanguage,
        array $heldAccounts,
        int $websiteId
    ): array {
        $websiteAccountChanged = $this->isChangedWithoutPassword(
            $subdomain,
            $username,
            $password,
            $this->config->getWebsiteSubdomain($websiteId),
            $this->config->getWebsiteUsername($websiteId)
        );
        $websiteAccountError = [
            'field' => 'password',
            'message' => (string)__('The subdomain or username changed — enter the password of this account.'),
        ];
        if ($mode !== MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
            return $websiteAccountChanged ? [$websiteAccountError] : [];
        }

        $errors = [];
        foreach ((array)($data['accounts'] ?? []) as $account) {
            if (!is_array($account)) {
                continue;
            }
            $language = (string)($account['language'] ?? '');
            $storeId = $language === '' ? null : $this->representativeStoreId($heldAccounts, $language);
            $accountSubdomain = $this->normalizer->normalize((string)($account['subdomain'] ?? ''));
            $accountUsername = trim((string)($account['username'] ?? ''));
            if ($storeId === null || $accountSubdomain === '' || $accountUsername === '') {
                continue;
            }
            if ($this->isChangedWithoutPassword(
                $accountSubdomain,
                $accountUsername,
                (string)($account['password'] ?? ''),
                $this->config->getSubdomain($storeId),
                $this->config->getUsername($storeId)
            )) {
                $errors[] = [
                    'field' => 'accounts.' . $language . '.password',
                    'message' => (string)__(
                        'The subdomain or username of the %1 account changed — enter its password.',
                        strtoupper($language)
                    ),
                ];
            }
        }

        // The top-level credentials: the default fallback account, asked for
        // on its block unless that block already has its own error.
        if ($websiteAccountChanged && $fallbackLanguage !== '') {
            $websiteAccountError = [
                'field' => 'accounts.' . $fallbackLanguage . '.password',
                'message' => (string)__(
                    'Enter the password of the %1 account so it can take over as the default fallback.',
                    strtoupper($fallbackLanguage)
                ),
            ];
        }
        if ($websiteAccountChanged && !in_array($websiteAccountError['field'], array_column($errors, 'field'), true)) {
            $errors[] = $websiteAccountError;
        }

        return $errors;
    }

    /**
     * The first store view, in the website's store view order, that held an
     * account for the language — the store view
     * AccountResolver::storeIdForAccountKey() names.
     *
     * @param array<int, array{language: string, account: string, password: string}> $heldAccounts
     */
    private function representativeStoreId(array $heldAccounts, string $language): ?int
    {
        foreach ($heldAccounts as $storeId => $store) {
            if ($store['language'] === $language) {
                return $storeId;
            }
        }

        return null;
    }

    /**
     * A posted password as a new password, or '' when the post keeps the
     * saved one: no password, or the masked placeholder.
     */
    private function newPassword(string $password): string
    {
        return $this->credentialCheck->isKeptPassword($password) ? '' : $password;
    }

    /**
     * Removes a store view's own credentials, so it uses the website's.
     */
    private function deleteStoreViewCredentials(int $storeId): void
    {
        foreach ([Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD] as $path) {
            $this->configWriter->delete($path, ScopeInterface::SCOPE_STORES, $storeId);
        }
    }

    /**
     * Whether a post keeps the saved password ('') for an account other
     * than the saved one.
     */
    private function isChangedWithoutPassword(
        string $subdomain,
        string $username,
        string $password,
        string $savedSubdomain,
        string $savedUsername
    ): bool {
        if ($password !== '') {
            return false;
        }

        return strcasecmp($subdomain, $savedSubdomain) !== 0 || $username !== $savedUsername;
    }

    /**
     * Each store view of the website with its current language and the
     * account it uses now: its subdomain and username, and its password.
     *
     * @return array<int, array{language: string, account: string, password: string}>
     */
    private function heldAccounts(int $websiteId): array
    {
        $held = [];
        foreach ($this->accountResolver->storeLanguages($websiteId) as $storeId => $language) {
            $held[$storeId] = [
                'language' => $language,
                'account' => $this->config->getSubdomain($storeId) . "\n" . $this->config->getUsername($storeId),
                'password' => $this->config->getPassword($storeId),
            ];
        }

        return $held;
    }

    /**
     * Mode A: every store view of this website gets the account of its
     * current language as store-view credentials, so a store view whose
     * language changed stops using the old language's account (PRO-3683).
     * A language whose block has no subdomain and no username has no
     * account: its store views lose their store-view credentials and use
     * the website's account. A block with only one of the two is skipped,
     * its store views keep what they have. An empty password keeps the
     * saved one: a store view that already uses the account keeps its own,
     * any other store view gets the password of a store view that uses it.
     *
     * @param array<mixed> $posted
     * @param array<int, array{language: string, account: string, password: string}> $held
     */
    private function savePerLanguageAccounts(array $posted, array $held): void
    {
        $accounts = [];
        $skipped = [];
        foreach ($posted as $account) {
            if (!is_array($account) || (string)($account['language'] ?? '') === '') {
                continue;
            }
            $language = (string)$account['language'];
            $subdomain = $this->normalizer->normalize((string)($account['subdomain'] ?? ''));
            $username = trim((string)($account['username'] ?? ''));
            if ($subdomain === '' || $username === '') {
                if ($subdomain !== '' || $username !== '') {
                    $skipped[$language] = true;
                }
                continue;
            }
            $accounts[$language] = [
                'subdomain' => $subdomain,
                'username' => $username,
                'password' => (string)($account['password'] ?? ''),
            ];
        }

        foreach ($held as $storeId => $store) {
            if (isset($skipped[$store['language']])) {
                continue;
            }
            $account = $accounts[$store['language']] ?? null;
            if ($account === null) {
                $this->deleteStoreViewCredentials($storeId);
                continue;
            }
            $this->configWriter->save(
                Config::XML_PATH_SUBDOMAIN,
                $account['subdomain'],
                ScopeInterface::SCOPE_STORES,
                $storeId
            );
            $this->configWriter->save(
                Config::XML_PATH_USERNAME,
                $account['username'],
                ScopeInterface::SCOPE_STORES,
                $storeId
            );
            $password = $account['password'];
            $key = $account['subdomain'] . "\n" . $account['username'];
            if ($password === '' && $store['account'] !== $key) {
                foreach ($held as $other) {
                    if ($other['account'] === $key && $other['password'] !== '') {
                        $password = $other['password'];
                        break;
                    }
                }
            }
            if ($password !== '') {
                $this->configWriter->save(
                    Config::XML_PATH_PASSWORD,
                    $this->encryptor->encrypt($password),
                    ScopeInterface::SCOPE_STORES,
                    $storeId
                );
            }
        }
    }

    /**
     * Saving the connection is a real check (PRO-3560): Smaily is asked once
     * whether it accepts the credentials just saved, and SmailyClient
     * remembers the answer for the Dashboard and the Connection status. The
     * save never depends on it — a refusal or an unreachable Smaily is shown
     * as "Not connected", not as a failed save.
     *
     * The account checked is the one saved at website scope — the single
     * account, or in mode A the default fallback account. An empty or masked
     * password keeps that scope's password, which this request has not
     * changed; not the website's default store view's, which in mode A can
     * belong to another language's account, and which the configuration
     * read in this request still shows after leaving mode A removed it
     * (PRO-3717).
     *
     * $password is the post's new password, or '' when it keeps the saved one.
     */
    private function checkCredentials(string $subdomain, string $username, string $password, int $websiteId): void
    {
        if ($password === '') {
            $password = $this->config->getWebsitePassword($websiteId);
        }

        try {
            $this->connectionAccepted = $this->credentialCheck->check($subdomain, $username, $password);
        } catch (SmailyClientException) {
            // Remembered (or not) by SmailyClient; the save stands either way.
        }
    }

    /**
     * The Contacts settings, written at $target's scope (null = the default
     * scope, for every website without its own Contacts settings).
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveSubscribers(array $data, ?int $target): array
    {
        $this->saveFlag(Config::XML_PATH_SYNC_ENABLED, $data, 'sync_enabled', $target);
        $this->saveFlag(Config::XML_PATH_INCLUDE_GUESTS, $data, 'include_guests', $target);
        $this->saveFlag(Config::XML_PATH_CHECKOUT_OPTIN_ENABLED, $data, 'checkout_optin_enabled', $target);
        $this->saveFlag(Config::XML_PATH_SUPPRESS_OPTIN_EMAILS, $data, 'suppress_optin_emails', $target);

        $mode = (string)($data['sync_mode'] ?? '');
        if (in_array($mode, [
            SyncMode::MODE_CONSENT,
            SyncMode::MODE_LEGITIMATE_INTEREST,
            SyncMode::MODE_CHECKOUT_OPTIN,
        ], true)) {
            $this->write(Config::XML_PATH_SYNC_MODE, $mode, $target);
        }

        if (isset($data['sync_fields']) && is_array($data['sync_fields'])) {
            $fields = array_values(array_intersect(
                SyncFields::SUPPORTED_FIELDS,
                array_map('strval', $data['sync_fields'])
            ));
            $this->write(Config::XML_PATH_SYNC_FIELDS, implode(',', $fields), $target);
        }

        // The merchant has now chosen how contacts sync: contact sync starts
        // here, not with the connection (PRO-4036) — a store-wide save on
        // every website that reads its mark from the default scope, the
        // website's own save on that website (CON-179).
        $this->write(Config::XML_PATH_CONTACTS_CONFIRMED, '1', $target);

        return [];
    }

    /**
     * The automations, read from $websiteId's saved values and written at
     * $target's scope (null = the default scope; the mapping rows then
     * under website 0).
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function saveAutomations(array $data, int $websiteId, ?int $target): array
    {
        $couponErrors = $this->couponErrors($data);
        if ($couponErrors !== []) {
            return $couponErrors;
        }
        $this->saveFlag(Config::XML_PATH_WELCOME_ENABLED, $data, 'welcome_enabled', $target);
        $this->saveFlag(Config::XML_PATH_FIRST_ORDER_ENABLED, $data, 'first_order_enabled', $target);
        $this->saveFlag(Config::XML_PATH_ABANDONED_ENABLED, $data, 'abandoned_enabled', $target);

        // A saved workflow id that is missing from the freshly loaded Smaily
        // list was never offered in the select, so an empty post is not a
        // deliberate clear — the stored binding is kept rather than dropped
        // (PRO-1286, same rule as the engine-automations single mode). Only
        // resolved lazily below, when a workflow key is actually posted.
        $availableWorkflowIds = null;
        // A website on another Smaily account has only its own workflows
        // (CON-190): a store-wide one it inherits is not its saved workflow.
        $ownAccount = $target !== null && $this->overrides->usesOtherAccount($websiteId);
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
            if ($ownAccount) {
                $savedWorkflow = (int)$this->overrides->ownValue(WebsiteOverrides::AUTOMATIONS, $websiteId, $path);
            }
            $postedId = ($id = (int)$data[$key]) > 0 ? (string)$id : '';
            $savedId = $savedWorkflow > 0 ? (string)$savedWorkflow : '';
            $availableWorkflowIds ??= $this->workflowIdsForWebsite($websiteId);
            if ($this->rowNormalizer->isMissingFromList($postedId, $savedId, $availableWorkflowIds)) {
                // Missing-from-list preserve: leave the stored value untouched.
                continue;
            }
            $this->write($path, $postedId === '' ? '0' : $postedId, $target);
        }

        if (array_key_exists('abandoned_cutoff', $data)) {
            $this->write(
                Config::XML_PATH_ABANDONED_CUTOFF,
                (string)max(Config::MIN_ABANDONED_CUTOFF_MINUTES, min(1440, (int)$data['abandoned_cutoff'])),
                $target
            );
        }
        foreach (self::COUPON_KEYS as $trigger => $key) {
            $paths = Config::COUPON_PATHS[$trigger];
            $this->saveFlag($paths['enabled'], $data, $key . '_coupon_enabled', $target);
            if (array_key_exists($key . '_coupon_rule', $data)) {
                $this->write($paths['rule'], (string)max(0, (int)$data[$key . '_coupon_rule']), $target);
            }
            if (array_key_exists($key . '_coupon_days', $data)) {
                $days = max(1, min(Config::MAX_COUPON_DAYS, (int)$data[$key . '_coupon_days']));
                $this->write($paths['days'], (string)$days, $target);
            }
        }

        // Per-language workflow mappings (multilingual modes a/b). The panel
        // sends the full desired state, so absent selections delete their
        // rows; single/c saves omit the key and leave the table untouched.
        // The per-account available-workflow lists let the saver preserve a
        // saved mapping row whose id is missing from its account's live list
        // instead of dropping it on the full sync (PRO-1286). Store-wide
        // rows are website 0's; a website's own rows are its own, which the
        // Router prefers over website 0's.
        // Only the rows of the mode the panel posts them for are replaced:
        // the other per-language mode's rows stay for a switch back (CON-188).
        if (isset($data['mappings']) && is_array($data['mappings'])) {
            return $this->mappingSaver->save(
                $data['mappings'],
                $target ?? 0,
                $this->availableWorkflowIdsByAccount($websiteId),
                (string)($data['mappings_mode'] ?? $this->config->getMultilingualMode($websiteId))
            );
        }

        return [];
    }

    /**
     * An automation whose "Send a discount code" switch is on needs a cart
     * price rule to make its codes from (CON-198); nothing is saved without
     * one.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{field: string, message: string}>
     */
    private function couponErrors(array $data): array
    {
        $errors = [];
        foreach (self::COUPON_KEYS as $key) {
            if (!empty($data[$key . '_coupon_enabled']) && (int)($data[$key . '_coupon_rule'] ?? 0) <= 0) {
                $errors[] = [
                    'field' => $key . '_coupon_rule',
                    'message' => (string)__(
                        'Send a discount code is on, but no cart price rule is picked. Pick a rule, or switch the'
                        . ' discount code off.'
                    ),
                ];
            }
        }

        return $errors;
    }

    /**
     * Available workflow ids per mapping account key: 'default' (the
     * website's account, mode B's one account) plus each language detected
     * on the given website (mode A, where a language's rows route through
     * that language's own Smaily account). An account whose list cannot be
     * loaded maps to an empty list, which the saver reads as "unknown" and
     * preserves.
     *
     * @return array<string, array<int, string>>
     */
    private function availableWorkflowIdsByAccount(int $websiteId): array
    {
        $byAccount = [Mapping::ACCOUNT_DEFAULT => $this->workflowIdsForWebsite($websiteId)];
        // A store-wide save on a 2+-website install has no per-language
        // accounts (saveConnect()).
        if ($websiteId === WebsiteContext::STORE_WIDE) {
            return $byAccount;
        }
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
        return $this->workflowIds(fn (): SmailyClient => $this->smailyClientProvider->forStore($storeId));
    }

    /**
     * Workflow ids the website's Smaily account can list — its own, else the
     * store-wide one (website 0), the account its 'default' rows and its
     * config-default workflows are posted through (CON-189). Empty when the
     * list is unavailable.
     *
     * @return array<int, string>
     */
    private function workflowIdsForWebsite(int $websiteId): array
    {
        return $this->workflowIds(fn (): SmailyClient => $this->smailyClientProvider->forWebsite($websiteId));
    }

    /**
     * @param \Closure(): SmailyClient $client
     * @return array<int, string>
     */
    private function workflowIds(\Closure $client): array
    {
        try {
            $workflows = $client()->getAutomationWorkflows();
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
    private function saveFinish(): array
    {
        // One mark for the store (CON-179).
        $this->configWriter->save(self::XML_PATH_SETUP_COMPLETED, '1');
        // The install's "ready to set up" notice has done its job.
        $this->setupNotice->markRead();

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
        $this->write($path, $data[$key] ? '1' : '0', $websiteId);
    }
}
