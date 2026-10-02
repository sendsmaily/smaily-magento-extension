<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * PRO-3683: with per-language Smaily accounts, saving the accounts gives
 * every store view of the website the account of its current language —
 * a store view whose language changed stops using the old language's
 * account, and one whose language has no account uses the website's. Read
 * back through the real Config getters over core_config_data.
 */
class PerLanguageAccountsSaveTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 7;

    private const OTHER_WEBSITE_ID = 8;

    /** Store view id => website id. */
    private const STORES = [1 => self::WEBSITE_ID, 2 => self::WEBSITE_ID, 3 => self::WEBSITE_ID,
        5 => self::WEBSITE_ID, 4 => self::OTHER_WEBSITE_ID];

    /**
     * Website id => its default store view id, the store view the
     * Connection panel draws the single account from.
     */
    private const DEFAULT_STORES = [self::WEBSITE_ID => 2, self::OTHER_WEBSITE_ID => 4];

    private const LOCALE = 'general/locale/code';

    private WizardStepSaver $saver;

    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $scopeConfig = new DatabaseScopeConfig($this->connection, self::STORES);
        $this->config = $this->objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);

        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $storeManager->method('getWebsite')->willReturnCallback(function (int $websiteId): Website {
            $storeIds = array_keys(array_filter(self::STORES, static fn (int $id): bool => $id === $websiteId));
            $stores = [];
            foreach ($storeIds as $storeId) {
                $stores[$storeId] = $this->createMock(Store::class);
                $stores[$storeId]->method('getId')->willReturn($storeId);
            }
            $website = $this->createMock(Website::class);
            $website->method('getStoreIds')->willReturn($storeIds);
            $website->method('getStores')->willReturn($stores);
            $website->method('getDefaultStore')->willReturn($stores[self::DEFAULT_STORES[$websiteId]]);

            return $website;
        });
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn(null);

        $this->saver = new WizardStepSaver(
            $this->objectManager->get(WriterInterface::class),
            $this->objectManager->get(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            new SubdomainNormalizer(),
            new AccountResolver($storeManager, new LanguageResolver($scopeConfig)),
            $this->config,
            new WebsiteContext($storeManager, $request),
            $storeManager,
            $this->objectManager->get(MappingSaver::class),
            $this->createMock(SmailyClientProvider::class),
            new ConfigRowNormalizer(),
            new CredentialCheck($this->createMock(SmailyClientFactory::class), $this->config),
            $this->createMock(SetupNotice::class)
        );

        // The other website's store view holds its own Estonian account.
        $this->setStoreLocale(4, 'et_EE');
        foreach ([
            Config::XML_PATH_SUBDOMAIN => 'other-et',
            Config::XML_PATH_USERNAME => 'other-et-user',
            Config::XML_PATH_PASSWORD => $this->encrypt('other-et-secret'),
        ] as $path => $value) {
            $this->connection->insert('core_config_data', [
                'scope' => 'stores',
                'scope_id' => 4,
                'path' => $path,
                'value' => $value,
            ]);
        }
    }

    public function testAStoreViewWhoseLanguageChangedGetsTheNewLanguagesAccount(): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'et_EE', 5 => 'en_GB']);
        $this->saveAccounts([$this->account('en', 'en-secret'), $this->account('et', 'et-secret')]);

        $this->setStoreLocale(3, 'fi_FI');
        $this->saveAccounts([$this->account('en'), $this->account('et'), $this->account('fi', 'fi-secret')]);

        $this->assertAccount(3, 'fi');
        $this->assertAccount(2, 'et', 'An empty password keeps the saved one');
        $this->assertAccount(1, 'en');
        $this->assertOtherWebsiteUntouched();
    }

    /**
     * The fi block is drawn from store view 3, the first fi store view, which
     * already holds the fi account; store view 5 moves to fi.
     */
    public function testAnEmptyPasswordTakesThePasswordOfAStoreViewThatUsesTheAccount(): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'fi_FI', 5 => 'et_EE']);
        $this->saveAccounts([
            $this->account('en', 'en-secret'),
            $this->account('et', 'et-secret'),
            $this->account('fi', 'fi-secret'),
        ]);

        $this->setStoreLocale(5, 'fi_FI');
        $this->saveAccounts([$this->account('en'), $this->account('et'), $this->account('fi')]);

        $this->assertAccount(5, 'fi');
        $this->assertAccount(3, 'fi');
        $this->assertAccount(2, 'et');
    }

    /**
     * PRO-3690: the fi block is drawn from store view 3, which still holds
     * the et account after moving to fi. A new account typed into it with
     * an empty password is refused on the block's password field, and
     * nothing is saved — store view 3 is not left with the fi account and
     * the et password.
     */
    public function testANewAccountInABlockDrawnWithAnotherAccountNeedsItsPassword(): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'et_EE', 5 => 'en_GB']);
        $this->saveAccounts([$this->account('en', 'en-secret'), $this->account('et', 'et-secret')]);

        $this->setStoreLocale(3, 'fi_FI');
        $accounts = [$this->account('en'), $this->account('et'), $this->account('fi')];
        $errors = $this->saver->save('connect', [
            'subdomain' => 'en-shop',
            'username' => 'en-user',
            'password' => '',
            'multilingual_mode' => 'a',
            'fallback_language' => 'en',
            'accounts' => $accounts,
        ]);

        self::assertSame([[
            'field' => 'accounts.fi.password',
            'message' => 'The subdomain or username of the FI account changed — enter its password.',
        ]], $errors);
        $this->assertAccount(3, 'et');
        $this->assertAccount(2, 'et');
        $this->assertAccount(1, 'en');
    }

    /**
     * @param array<int, array<string, string>> $finnishBlock
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('noFinnishAccount')]
    public function testAStoreViewWhoseLanguageHasNoAccountUsesTheWebsitesAccount(array $finnishBlock): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'et_EE', 5 => 'en_GB']);
        $this->saveAccounts([$this->account('en', 'en-secret'), $this->account('et', 'et-secret')]);

        $this->setStoreLocale(3, 'fi_FI');
        $this->saveAccounts(array_merge([$this->account('en'), $this->account('et')], $finnishBlock));

        $this->assertAccount(3, 'en', 'The website account (the en fallback) serves store view 3');
        foreach ([Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD] as $path) {
            self::assertSame([], $this->storeViewRows(3, $path), $path);
        }
        $this->assertAccount(2, 'et');
        $this->assertOtherWebsiteUntouched();
    }

    /**
     * @return array<string, array{0: array<int, array<string, string>>}>
     */
    public static function noFinnishAccount(): array
    {
        return [
            'no block' => [[]],
            'blank block' => [[['language' => 'fi', 'subdomain' => '', 'username' => '', 'password' => '']]],
        ];
    }

    public function testABlockWithOnlyASubdomainLeavesItsStoreViewsAsTheyAre(): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'et_EE', 5 => 'en_GB']);
        $this->saveAccounts([$this->account('en', 'en-secret'), $this->account('et', 'et-secret')]);

        $this->saveAccounts([
            $this->account('en'),
            ['language' => 'et', 'subdomain' => 'changed-et', 'username' => '', 'password' => ''],
        ]);

        $this->assertAccount(2, 'et');
        $this->assertAccount(3, 'et');
    }

    /**
     * PRO-3699: after per-language accounts, the single account fields show
     * the default store view's account — store view 2's et account, not the
     * website's en fallback. Saved unchanged as the single account with an
     * empty password, it would be written for the whole website with the en
     * password. It is refused on the password field, and nothing is saved:
     * the store views keep their accounts and mode A stays.
     */
    public function testLeavingPerLanguageAccountsWithTheDefaultStoreViewsAccountNeedsItsPassword(): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'et_EE', 5 => 'en_GB']);
        $this->saveAccounts([$this->account('en', 'en-secret'), $this->account('et', 'et-secret')]);

        $errors = $this->saveSingle($this->account('et'));

        self::assertSame([[
            'field' => 'password',
            'message' => 'The subdomain or username changed — enter the password of this account.',
        ]], $errors);
        self::assertSame('a', $this->config->getMultilingualMode(self::WEBSITE_ID));
        $this->assertAccount(2, 'et');
        $this->assertAccount(3, 'et');
        $this->assertAccount(1, 'en');
    }

    /**
     * PRO-3699: leaving per-language accounts keeps a working account — the
     * website's own (the en fallback) with an empty password, the default
     * store view's account with its password; every store view of the
     * website then uses it.
     *
     * @param array<string, string> $single
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('singleAccountAfterPerLanguageAccounts')]
    public function testLeavingPerLanguageAccountsKeepsAWorkingAccount(array $single, string $language): void
    {
        $this->setLocales([1 => 'en_US', 2 => 'et_EE', 3 => 'et_EE', 5 => 'en_GB']);
        $this->saveAccounts([$this->account('en', 'en-secret'), $this->account('et', 'et-secret')]);

        self::assertSame([], $this->saveSingle($single));

        foreach ([1, 2, 3, 5] as $storeId) {
            $this->assertAccount($storeId, $language, 'Store view ' . $storeId);
        }
        $this->assertOtherWebsiteUntouched();
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function singleAccountAfterPerLanguageAccounts(): array
    {
        return [
            'the website account, empty password' => [
                ['subdomain' => 'en-shop', 'username' => 'en-user', 'password' => ''],
                'en',
            ],
            'the default store view account, its password' => [
                ['subdomain' => 'et-shop', 'username' => 'et-user', 'password' => 'et-secret'],
                'et',
            ],
        ];
    }

    /**
     * A single-account save as the Connection panel posts it after the mode
     * is switched away from per-language accounts.
     *
     * @param array<string, string> $account
     * @return array<int, array{field: string, message: string}>
     */
    private function saveSingle(array $account): array
    {
        return $this->saver->save('connect', [
            'subdomain' => $account['subdomain'],
            'username' => $account['username'],
            'password' => $account['password'],
            'multilingual_mode' => 'single',
        ]);
    }

    /**
     * A mode-A save as the Connection panel posts it: the en account is the
     * fallback, so its credentials are also the top-level ones.
     *
     * @param array<int, array<string, string>> $accounts
     */
    private function saveAccounts(array $accounts): void
    {
        $errors = $this->saver->save('connect', [
            'subdomain' => $accounts[0]['subdomain'],
            'username' => $accounts[0]['username'],
            'password' => $accounts[0]['password'],
            'multilingual_mode' => 'a',
            'fallback_language' => 'en',
            'accounts' => $accounts,
        ]);
        self::assertSame([], $errors);
    }

    /**
     * @return array<string, string>
     */
    private function account(string $language, string $password = ''): array
    {
        return [
            'language' => $language,
            'subdomain' => $language . '-shop',
            'username' => $language . '-user',
            'password' => $password,
        ];
    }

    private function assertAccount(int $storeId, string $language, string $message = ''): void
    {
        self::assertSame(
            [$language . '-shop', $language . '-user', $language . '-secret'],
            [
                $this->config->getSubdomain($storeId),
                $this->config->getUsername($storeId),
                $this->config->getPassword($storeId),
            ],
            $message
        );
    }

    private function assertOtherWebsiteUntouched(): void
    {
        self::assertSame(
            ['other-et', 'other-et-user', 'other-et-secret'],
            [$this->config->getSubdomain(4), $this->config->getUsername(4), $this->config->getPassword(4)]
        );
    }

    /**
     * @param array<int, string> $locales store view id => locale
     */
    private function setLocales(array $locales): void
    {
        foreach ($locales as $storeId => $locale) {
            $this->setStoreLocale($storeId, $locale);
        }
    }

    private function setStoreLocale(int $storeId, string $locale): void
    {
        $this->connection->delete('core_config_data', [
            'scope = ?' => 'stores',
            'scope_id = ?' => $storeId,
            'path = ?' => self::LOCALE,
        ]);
        $this->connection->insert('core_config_data', [
            'scope' => 'stores',
            'scope_id' => $storeId,
            'path' => self::LOCALE,
            'value' => $locale,
        ]);
    }

    private function encrypt(string $value): string
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);

        return $encryptor->encrypt($value);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeViewRows(int $storeId, string $path): array
    {
        return $this->connection->fetchAll(
            $this->connection->select()->from('core_config_data')
                ->where('scope = ?', 'stores')
                ->where('scope_id = ?', $storeId)
                ->where('path = ?', $path)
        );
    }
}
