<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\Exception\InvalidSubdomainException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\SmailyUrl;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Saves the transactional-email settings (PRO-4062), for WizardStepSaver:
 * the "Send transactional emails" switch and the separate Smaily account on
 * the Connection tab or the setup's Connect step, and the Order confirmation
 * and Shipping confirmation triggers and the Customer details switch on the
 * Automations tab or step, as the WooCommerce plugin has them (CON-33).
 * Nothing here sends an email or changes Magento's own order and shipment
 * emails.
 *
 * The switches are saved for the website; the initial setup of an install
 * with more than one website (WebsiteContext::STORE_WIDE) saves them for all
 * websites (default scope), and its trigger workflows are website 0's rows,
 * as the store-wide automations are. While "Send transactional emails" is
 * off, the account fields are hidden and a save leaves the saved account as
 * it is (the WooCommerce plugin keeps its credentials too).
 *
 * The account is saved for all websites (default scope). On an install with
 * more than one website, a website may use its own account instead
 * (`website_account`): it is saved at that website's scope and marked with
 * Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT. Saving a website without
 * its own account removes that website's own rows, so it follows the account
 * saved for all websites. All three fields empty removes the account at the
 * scope the save targets.
 */
class TransactionalSettingsSaver
{
    private const FIELDS = [
        Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN,
        Config::XML_PATH_TRANSACTIONAL_USERNAME,
        Config::XML_PATH_TRANSACTIONAL_PASSWORD,
    ];

    /** Whether Smaily accepted the account the last save in this request checked. */
    private bool $accountAccepted = false;

    public function __construct(
        private readonly WriterInterface $configWriter,
        private readonly EncryptorInterface $encryptor,
        private readonly SubdomainNormalizer $normalizer,
        private readonly Config $config,
        private readonly WebsiteContext $websiteContext,
        private readonly CredentialCheck $credentialCheck,
        private readonly MappingSaver $mappingSaver
    ) {
    }

    /**
     * What is wrong with a posted account; empty when it can be saved. Each
     * error names its field as "transactional.<field>".
     *
     * @param array<string, mixed> $posted {enabled, subdomain, username, password, website_account?}
     * @return array<int, array{field: string, message: string}>
     */
    public function accountErrors(array $posted, int $websiteId): array
    {
        if (!$this->switchedOn($posted)) {
            return [];
        }
        [$subdomain, $username, $password] = $this->postedAccount($posted);
        if ($subdomain === '' && $username === '' && $password === '') {
            return [];
        }
        $drawnFrom = $this->ownAccount($posted) ? $websiteId : null;

        $errors = [];
        foreach (['subdomain' => $subdomain, 'username' => $username] as $field => $value) {
            if ($value === '') {
                $errors[] = [
                    'field' => 'transactional.' . $field,
                    'message' => (string)__('Subdomain and username are required.'),
                ];
            }
        }
        if ($errors !== []) {
            return $errors;
        }
        if (!SmailyUrl::isPlainSubdomain($subdomain)) {
            return [[
                'field' => 'transactional.subdomain',
                'message' => (new InvalidSubdomainException())->getMessage(),
            ]];
        }
        if ($password !== '') {
            return [];
        }

        // An empty password keeps the saved one, but only for the account
        // the form was drawn with (the PRO-3690 rule of the marketing
        // account): the website's own, or the one saved for all websites.
        if (strcasecmp($subdomain, $this->config->getTransactionalSubdomain($drawnFrom)) !== 0
            || $username !== $this->config->getTransactionalUsername($drawnFrom)
        ) {
            return [[
                'field' => 'transactional.password',
                'message' => (string)__('The subdomain or username changed — enter the password of this account.'),
            ]];
        }
        if ($this->config->getTransactionalPassword($drawnFrom) === '') {
            return [[
                'field' => 'transactional.password',
                'message' => (string)__('Please fill in the subdomain, username and password.'),
            ]];
        }

        return [];
    }

    /**
     * Saves the "Send transactional emails" switch for the website and, when
     * it is on, a posted account that accountErrors() accepted, then asks
     * Smaily whether it accepts it (isAccountAccepted()); the save never
     * depends on the answer.
     *
     * @param array<string, mixed> $posted {enabled, subdomain, username, password, website_account?}
     */
    public function saveAccount(array $posted, int $websiteId): void
    {
        $this->accountAccepted = false;
        $this->saveSwitch(Config::XML_PATH_TRANSACTIONAL_ENABLED, $this->switchedOn($posted), $websiteId);
        if (!$this->switchedOn($posted)) {
            return;
        }
        [$subdomain, $username, $password] = $this->postedAccount($posted);
        $ownAccount = $this->ownAccount($posted);
        $removing = $subdomain === '' && $username === '' && $password === '';
        if ($password === '' && !$removing) {
            $password = $this->config->getTransactionalPassword($ownAccount ? $websiteId : null);
        }

        // The website's own rows go when it follows the account saved for
        // all websites, and when its own account is emptied.
        if (!$ownAccount || $removing) {
            foreach ([...self::FIELDS, Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT] as $path) {
                $this->configWriter->delete($path, ScopeInterface::SCOPE_WEBSITES, $websiteId);
            }
        }
        if ($removing) {
            if (!$ownAccount) {
                foreach (self::FIELDS as $path) {
                    $this->configWriter->delete($path);
                }
            }

            return;
        }

        [$scope, $scopeId] = $ownAccount
            ? [ScopeInterface::SCOPE_WEBSITES, $websiteId]
            : [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 0];
        $this->configWriter->save(Config::XML_PATH_TRANSACTIONAL_SUBDOMAIN, $subdomain, $scope, $scopeId);
        $this->configWriter->save(Config::XML_PATH_TRANSACTIONAL_USERNAME, $username, $scope, $scopeId);
        $this->configWriter->save(
            Config::XML_PATH_TRANSACTIONAL_PASSWORD,
            $this->encryptor->encrypt($password),
            $scope,
            $scopeId
        );
        if ($ownAccount) {
            $this->configWriter->save(
                Config::XML_PATH_TRANSACTIONAL_WEBSITE_ACCOUNT,
                '1',
                ScopeInterface::SCOPE_WEBSITES,
                $websiteId
            );
        }

        try {
            $this->accountAccepted = $this->credentialCheck->check($subdomain, $username, $password);
        } catch (SmailyClientException) {
            // Remembered (or not) by SmailyClient; the save stands either way.
        }
    }

    /**
     * Whether Smaily accepted the account the last saveAccount() in this
     * request checked — read from the check itself, because the
     * configuration cache can still hold the account from before the save.
     */
    public function isAccountAccepted(): bool
    {
        return $this->accountAccepted;
    }

    /**
     * Saves a website's Order confirmation and Shipping confirmation
     * switches and their workflows per store language, and its Customer
     * details switch.
     *
     * @param array<string, mixed> $posted {order_confirmation_enabled, shipment_confirmation_enabled,
     *     personal_data_enabled, mappings}
     * @return array<int, array{field: string, message: string}> empty on success
     */
    public function saveTriggers(array $posted, int $websiteId): array
    {
        foreach ([
            'order_confirmation_enabled' => Config::XML_PATH_ORDER_CONFIRMATION_ENABLED,
            'shipment_confirmation_enabled' => Config::XML_PATH_SHIPMENT_CONFIRMATION_ENABLED,
            'personal_data_enabled' => Config::XML_PATH_TRANSACTIONAL_PERSONAL_DATA,
        ] as $key => $path) {
            if (array_key_exists($key, $posted)) {
                $this->saveSwitch($path, (bool)$posted[$key], $websiteId);
            }
        }

        return is_array($posted['mappings'] ?? null)
            ? $this->mappingSaver->saveTransactional($posted['mappings'], $websiteId)
            : [];
    }

    /**
     * Saves a switch for the website, or for all websites when the initial
     * setup of a 2+-website install saves it (WebsiteContext::STORE_WIDE).
     */
    private function saveSwitch(string $path, bool $on, int $websiteId): void
    {
        if ($websiteId === WebsiteContext::STORE_WIDE) {
            $this->configWriter->save($path, $on ? '1' : '0');

            return;
        }
        $this->configWriter->save($path, $on ? '1' : '0', ScopeInterface::SCOPE_WEBSITES, $websiteId);
    }

    /**
     * Whether the post has "Send transactional emails" switched on: only
     * then does it carry the account.
     *
     * @param array<string, mixed> $posted
     */
    private function switchedOn(array $posted): bool
    {
        return !empty($posted['enabled']);
    }

    /**
     * Whether the post saves the website's own account: only on an install
     * with more than one website.
     *
     * @param array<string, mixed> $posted
     */
    private function ownAccount(array $posted): bool
    {
        return !empty($posted['website_account']) && $this->websiteContext->hasMultipleWebsites();
    }

    /**
     * The posted subdomain, username and new password ('' when the post
     * keeps the saved one: empty, or the masked placeholder).
     *
     * @param array<string, mixed> $posted
     * @return string[]
     */
    private function postedAccount(array $posted): array
    {
        $password = (string)($posted['password'] ?? '');

        return [
            $this->normalizer->normalize((string)($posted['subdomain'] ?? '')),
            trim((string)($posted['username'] ?? '')),
            $this->credentialCheck->isKeptPassword($password) ? '' : $password,
        ];
    }
}
