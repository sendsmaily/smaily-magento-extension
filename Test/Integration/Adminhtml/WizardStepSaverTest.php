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
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Adminhtml\TransactionalSettingsSaver;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ModuleVersion;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * The store-wide setup (CON-179) against a real core_config_data table:
 * the initial setup saves every step at the default scope, a website keeps
 * its own values unless the merchant picks the step's values for it,
 * Settings gives one website its own values and returns it to the
 * store-wide ones, contact sync starts on a website only once the Contacts
 * settings that apply to it are saved, and a store set up before the update
 * keeps every row where it is.
 *
 * Two websites: Main (1, store view 1, the default website) and Second (7,
 * store view 7).
 */
class WizardStepSaverTest extends IntegrationTestCase
{
    private const MAIN = 1;
    private const SECOND = 7;

    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->objectManager->create(Config::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, [self::MAIN => self::MAIN, self::SECOND => self::SECOND]),
        ]);
    }

    /**
     * The saver as a request sees it: the initial setup's calls carry
     * website '0', Settings' the selected website's id.
     *
     * @param int[] $websiteIds the store's websites
     */
    private function saver(?string $websiteParam, array $websiteIds = [self::MAIN, self::SECOND]): WizardStepSaver
    {
        $storeManager = $this->storeManager($websiteIds);
        $smailyClientProvider = $this->createMock(SmailyClientProvider::class);
        $smailyClientProvider->method('forStore')->willThrowException(new SmailyClientException('not configured'));

        return new WizardStepSaver(
            $this->objectManager->get(WriterInterface::class),
            $this->objectManager->get(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            new SubdomainNormalizer(),
            $this->createMock(AccountResolver::class),
            $this->config,
            $this->websiteContext($storeManager, $websiteParam),
            $storeManager,
            $this->objectManager->get(MappingSaver::class),
            $smailyClientProvider,
            new ConfigRowNormalizer(),
            // Smaily is never asked: the client factory returns a mock.
            new CredentialCheck($this->createMock(SmailyClientFactory::class), $this->config),
            $this->createMock(SetupNotice::class),
            $this->createMock(TransactionalSettingsSaver::class),
            new WebsiteOverrides(
                $this->objectManager->get(ResourceConnection::class),
                $this->objectManager->get(WriterInterface::class),
                $storeManager
            )
        );
    }

    /**
     * @param int[] $websiteIds
     * @return StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function storeManager(array $websiteIds): StoreManagerInterface
    {
        $websites = [];
        foreach ($websiteIds as $websiteId) {
            $store = $this->createMock(\Magento\Store\Model\Store::class);
            $store->method('getId')->willReturn($websiteId);
            $website = $this->createMock(Website::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getName')->willReturn('Website ' . $websiteId);
            $website->method('getStoreIds')->willReturn([$websiteId]);
            $website->method('getStores')->willReturn([$store]);
            $website->method('getDefaultStore')->willReturn($store);
            $websites[$websiteId] = $website;
        }
        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::MAIN);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (int $websiteId) => $websites[$websiteId] ?? null
        );

        return $storeManager;
    }

    private function websiteContext(StoreManagerInterface $storeManager, ?string $websiteParam): WebsiteContext
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('website')->willReturn($websiteParam);

        return new WebsiteContext($storeManager, $request);
    }

    /**
     * Criterion 3: every value the initial setup saves applies to the whole
     * store — default-scope rows only, which every website reads.
     */
    public function testTheInitialSetupSavesEveryStepForTheWholeStore(): void
    {
        $saver = $this->saver('0');

        self::assertSame([], $saver->save('connect', [
            'subdomain' => 'shop',
            'username' => 'api-user',
            'password' => 'plain-secret',
            'multilingual_mode' => 'single',
            'storefront_url' => 'https://shop.example.com',
        ]));
        self::assertSame([], $saver->save('subscribers', ['sync_mode' => 'consent', 'sync_fields' => ['first_name']]));
        self::assertSame([], $saver->save('automations', ['welcome_enabled' => true, 'abandoned_cutoff' => 45]));
        self::assertSame([], $saver->save('finish', []));

        foreach ([
            Config::XML_PATH_SUBDOMAIN,
            Config::XML_PATH_USERNAME,
            Config::XML_PATH_PASSWORD,
            Config::XML_PATH_MULTILINGUAL_MODE,
            Config::XML_PATH_STOREFRONT_URL,
            Config::XML_PATH_SYNC_MODE,
            Config::XML_PATH_SYNC_FIELDS,
            Config::XML_PATH_CONTACTS_CONFIRMED,
            Config::XML_PATH_WELCOME_ENABLED,
            Config::XML_PATH_ABANDONED_CUTOFF,
            WizardStepSaver::XML_PATH_SETUP_COMPLETED,
        ] as $path) {
            self::assertSame([['default', 0]], $this->scopesOf($path), $path);
        }
        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            self::assertSame('shop', $this->config->getWebsiteSubdomain($websiteId));
            self::assertSame('plain-secret', $this->config->getWebsitePassword($websiteId));
            self::assertSame(45, $this->config->getAbandonedCutoffMinutes($websiteId));
        }
    }

    /**
     * Criterion 5: a website that holds its own value keeps it unless the
     * merchant picks the step's values for it; then its own rows go.
     */
    public function testAWebsitesOwnValueStaysUnlessTheMerchantPicksTheStepsValuesForIt(): void
    {
        $this->insertRow('websites', self::MAIN, Config::XML_PATH_SYNC_ENABLED, '0');
        $this->insertRow('websites', self::SECOND, Config::XML_PATH_SYNC_MODE, 'legitimate_interest');

        $errors = $this->saver('0')->save('subscribers', [
            'sync_enabled' => true,
            'sync_mode' => 'consent',
            'use_store_wide' => [self::SECOND],
        ]);

        self::assertSame([], $errors);
        self::assertSame([['default', 0], ['websites', self::MAIN]], $this->scopesOf(Config::XML_PATH_SYNC_ENABLED));
        self::assertSame([['default', 0]], $this->scopesOf(Config::XML_PATH_SYNC_MODE));
        self::assertFalse($this->config->isSyncEnabled(self::MAIN), 'Main keeps its own value');
        self::assertSame('consent', $this->config->getSyncMode(self::SECOND), 'Second now uses the step\'s value');
    }

    /**
     * Criterion 7: contact sync starts on a website only once the Contacts
     * settings that apply to it are saved. The Connect step starts none; a
     * store-wide Contacts save counts for every website without its own
     * Contacts settings; a website's own Contacts save in Settings counts
     * for that website.
     */
    public function testContactSyncStartsOnlyOnceTheContactsSettingsThatApplyAreSaved(): void
    {
        $this->saver('0')->save('connect', ['subdomain' => 'shop', 'username' => 'api-user', 'password' => 'secret']);

        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            self::assertTrue($this->config->isSyncEnabled($websiteId));
            self::assertFalse($this->config->isContactSyncActive($websiteId), 'Website ' . $websiteId);
        }

        $this->saver('0')->save('subscribers', ['sync_mode' => 'consent', 'sync_fields' => []]);

        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            self::assertTrue($this->config->isContactSyncActive($websiteId), 'Website ' . $websiteId);
        }

        // A website switched off for itself in Settings, then on again: its
        // own Contacts save marks its own settings saved.
        $this->saver((string)self::SECOND)->save('subscribers', ['own' => true, 'sync_enabled' => false]);
        self::assertFalse($this->config->isContactSyncActive(self::SECOND));
        $this->saver((string)self::SECOND)->save('subscribers', ['own' => true, 'sync_enabled' => true]);
        self::assertTrue($this->config->isContactSyncActive(self::SECOND));
        self::assertContains(['websites', self::SECOND], $this->scopesOf(Config::XML_PATH_CONTACTS_CONFIRMED));
    }

    /**
     * Criterion 6: Settings gives one website its own Smaily account,
     * Contacts settings and automations, and returns it to the store-wide
     * values; another website keeps the store-wide values throughout.
     */
    public function testSettingsGivesAWebsiteItsOwnValuesAndReturnsItToTheStoreWideValues(): void
    {
        $this->insertRow('default', 0, Config::XML_PATH_MULTILINGUAL_MODE, 'b');
        $this->saver('0')->save('connect', [
            'subdomain' => 'shop',
            'username' => 'api-user',
            'password' => 'secret',
            'multilingual_mode' => 'b',
        ]);
        $this->saver('0')->save('automations', ['mappings' => [
            ['trigger_type' => 'welcome', 'language' => 'default', 'account_key' => 'default', 'workflow_id' => 900],
        ]]);

        $settings = $this->saver((string)self::SECOND);
        self::assertSame([], $settings->save('connect', [
            'own' => true,
            'subdomain' => 'second-shop',
            'username' => 'api-second',
            'password' => 'second-secret',
            'multilingual_mode' => 'b',
        ]));
        self::assertSame([], $settings->save('subscribers', ['own' => true, 'sync_mode' => 'legitimate_interest']));
        self::assertSame([], $settings->save('automations', ['own' => true, 'mappings' => [
            ['trigger_type' => 'welcome', 'language' => 'default', 'account_key' => 'default', 'workflow_id' => 123],
        ]]));

        self::assertSame('second-shop', $this->config->getWebsiteSubdomain(self::SECOND));
        self::assertSame('second-secret', $this->config->getWebsitePassword(self::SECOND));
        self::assertSame('shop', $this->config->getWebsiteSubdomain(self::MAIN));
        self::assertSame('legitimate_interest', $this->config->getSyncMode(self::SECOND));
        $router = $this->objectManager->create(Router::class, ['config' => $this->config]);
        self::assertSame(123, $router->resolve('welcome', self::SECOND, 'default')?->workflowId);
        self::assertSame(900, $router->resolve('welcome', self::MAIN, 'default')?->workflowId);

        // Returned: unticked and saved, the website's own values go and
        // nothing else is written.
        foreach (['connect', 'subscribers', 'automations'] as $step) {
            $saver = $this->saver((string)self::SECOND);
            self::assertSame([], $saver->save($step, ['own' => false, 'subdomain' => 'ignored', 'username' => 'ignored']));
            self::assertTrue($saver->isReturnedToStoreWide(), $step);
        }

        self::assertSame('shop', $this->config->getWebsiteSubdomain(self::SECOND));
        self::assertSame('secret', $this->config->getWebsitePassword(self::SECOND));
        self::assertSame('consent', $this->config->getSyncMode(self::SECOND));
        self::assertSame(900, $router->resolve('welcome', self::SECOND, 'default')?->workflowId);
        self::assertSame([['default', 0]], $this->scopesOf(Config::XML_PATH_SUBDOMAIN));
        self::assertSame([0], array_map('intval', $this->connection->fetchCol(
            $this->connection->select()->from(MappingResource::TABLE_NAME, ['website_id'])
        )));
        self::assertTrue(
            $this->config->isContactSyncActive(self::SECOND),
            'Its contacts were confirmed; returning keeps them syncing'
        );
    }

    /**
     * On a store with one website there are no website exceptions: a save
     * writes the store's values and removes the website's own, which an
     * initial setup before the update saved — the saved password included,
     * so nothing it sends with changes.
     */
    public function testAStoreWithOneWebsiteMovesItsValuesToTheStoreOnlyWithASave(): void
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        $this->insertRow('websites', self::MAIN, Config::XML_PATH_SUBDOMAIN, 'shop');
        $this->insertRow('websites', self::MAIN, Config::XML_PATH_USERNAME, 'api-user');
        $this->insertRow('websites', self::MAIN, Config::XML_PATH_PASSWORD, $encryptor->encrypt('kept-secret'));

        $errors = $this->saver(null, [self::MAIN])->save('connect', [
            'subdomain' => 'shop',
            'username' => 'api-user',
            'password' => '',
        ]);

        self::assertSame([], $errors);
        foreach ([Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD] as $path) {
            self::assertSame([['default', 0]], $this->scopesOf($path), $path);
        }
        self::assertSame('kept-secret', $this->config->getPassword(self::MAIN));
    }

    /**
     * Per-language Smaily accounts are always a website's own (owner
     * decision 2026-10-22). The initial setup of a store with one website
     * saves mode A, its fallback account and the per-language workflows at
     * that website, not for the whole store. A second website added later
     * inherits none of it: it is not in mode A and no workflow of another
     * website's accounts reaches it; the first website is listed with its
     * own Smaily account and keeps it through a store-wide save.
     */
    public function testAStoreWithOneWebsiteInModeAThatAddsASecondWebsite(): void
    {
        $oneWebsite = $this->saver('0', [self::MAIN]);
        self::assertSame([], $oneWebsite->save('connect', [
            'subdomain' => 'en-shop',
            'username' => 'en-user',
            'password' => 'en-secret',
            'multilingual_mode' => 'a',
            'fallback_language' => 'en',
            'accounts' => [],
        ]));
        self::assertSame([], $this->saver('0', [self::MAIN])->save('automations', ['mappings' => [
            ['trigger_type' => 'welcome', 'language' => 'et', 'account_key' => 'et', 'workflow_id' => 77],
        ]]));

        foreach ([
            Config::XML_PATH_SUBDOMAIN,
            Config::XML_PATH_PASSWORD,
            Config::XML_PATH_MULTILINGUAL_MODE,
            Config::XML_PATH_FALLBACK_LANGUAGE,
        ] as $path) {
            self::assertSame([['websites', self::MAIN]], $this->scopesOf($path), $path);
        }
        self::assertSame([self::MAIN], array_map('intval', $this->connection->fetchCol(
            $this->connection->select()->from(MappingResource::TABLE_NAME, ['website_id'])
        )));
        self::assertSame('a', $this->config->getMultilingualMode(self::MAIN));

        // The store gains a second website, which reads the default scope.
        $this->insertRow('default', 0, Config::XML_PATH_MULTILINGUAL_MODE, 'b');
        self::assertSame('b', $this->config->getMultilingualMode(self::SECOND));
        self::assertSame('', $this->config->getWebsiteSubdomain(self::SECOND));
        $router = $this->objectManager->create(Router::class, ['config' => $this->config]);
        self::assertNull($router->resolve('welcome', self::SECOND, 'et'));
        self::assertSame(77, $router->resolve('welcome', self::MAIN, 'et')?->workflowId);

        $overrides = new WebsiteOverrides(
            $this->objectManager->get(ResourceConnection::class),
            $this->objectManager->get(WriterInterface::class),
            $this->storeManager([self::MAIN, self::SECOND])
        );
        self::assertSame([self::MAIN], array_keys($overrides->ownValues(WebsiteOverrides::CONNECTION)));

        self::assertSame([], $this->saver('0')->save('connect', [
            'subdomain' => 'shop',
            'username' => 'api-user',
            'password' => 'secret',
            'multilingual_mode' => 'b',
        ]));
        self::assertSame('a', $this->config->getMultilingualMode(self::MAIN), 'Main keeps its own Smaily account');
        self::assertSame('en-shop', $this->config->getWebsiteSubdomain(self::MAIN));
        self::assertSame('shop', $this->config->getWebsiteSubdomain(self::SECOND));
    }

    /**
     * Criteria 8 and 9: a store set up before the update — the initial
     * setup saved its values and its "setup completed" mark per website —
     * keeps every row where it is, keeps sending with them, and counts as
     * set up, so the admin does not send it back into the setup.
     */
    public function testAStoreSetUpBeforeTheUpdateKeepsItsRowsAndIsNotSentBackIntoTheSetup(): void
    {
        $this->insertRow('websites', self::SECOND, Config::XML_PATH_SUBDOMAIN, 'second-shop');
        $this->insertRow('websites', self::SECOND, Config::XML_PATH_SYNC_ENABLED, '1');
        $this->insertRow('websites', self::SECOND, Config::XML_PATH_CONTACTS_CONFIRMED, '1');
        $this->insertRow('websites', self::SECOND, WizardStepSaver::XML_PATH_SETUP_COMPLETED, '1');
        $before = $this->allConfigRows();
        $storeManager = $this->storeManager([self::MAIN, self::SECOND]);

        $guard = new SetupGuard(
            new DatabaseScopeConfig($this->connection, [self::MAIN => self::MAIN, self::SECOND => self::SECOND]),
            $this->objectManager->get(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(NotifierInterface::class),
            $this->createMock(ModuleVersion::class),
            $this->websiteContext($storeManager, null)
        );

        self::assertTrue($guard->isSetupCompleted());
        self::assertSame($before, $this->allConfigRows(), 'No row moved');
        self::assertSame('second-shop', $this->config->getWebsiteSubdomain(self::SECOND));
        self::assertTrue($this->config->isContactSyncActive(self::SECOND));
    }

    public function testAStoreNeverSetUpIsNotSetUp(): void
    {
        $guard = new SetupGuard(
            new DatabaseScopeConfig($this->connection, []),
            $this->objectManager->get(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(NotifierInterface::class),
            $this->createMock(ModuleVersion::class),
            $this->websiteContext($this->storeManager([self::MAIN, self::SECOND]), null)
        );

        self::assertFalse($guard->isSetupCompleted());
    }

    private function insertRow(string $scope, int $scopeId, string $path, string $value): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => $path,
            'value' => $value,
        ]);
    }

    /**
     * @return array<int, array{0: string, 1: int}> the scopes that hold a row of the path
     */
    private function scopesOf(string $path): array
    {
        $scopes = [];
        foreach ($this->connection->fetchAll(
            $this->connection->select()->from('core_config_data', ['scope', 'scope_id'])
                ->where('path = ?', $path)
                ->order(['scope ASC', 'scope_id ASC'])
        ) as $row) {
            $scopes[] = [(string)$row['scope'], (int)$row['scope_id']];
        }

        return $scopes;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function allConfigRows(): array
    {
        return $this->connection->fetchAll(
            $this->connection->select()->from('core_config_data', ['scope', 'scope_id', 'path', 'value'])
                ->order(['path ASC', 'scope ASC', 'scope_id ASC'])
        );
    }
}
