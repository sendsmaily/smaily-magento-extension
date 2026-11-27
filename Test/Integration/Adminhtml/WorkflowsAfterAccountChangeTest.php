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
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Adminhtml\TransactionalSettingsSaver;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;
use Smaily\Connect\Test\Integration\Support\Fake\RequestCachedScopeConfig;
use Smaily\Connect\Model\Adminhtml\WebsiteContacts;

/**
 * CON-396: a workflow belongs to the Smaily account it was picked from. A
 * connection save that puts another Smaily account (another subdomain) in
 * the place of a saved one removes the workflows picked from the saved one,
 * so no automation is sent through an account with another account's
 * workflow: it sends nothing (the Router finds no workflow, which the Log
 * shows as skipped) until a workflow of the new account is picked.
 *
 * One website (1) with an Estonian (1) and an English (2) store view, the
 * shape in which per-language accounts are the store's own setup.
 */
class WorkflowsAfterAccountChangeTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 1;

    /** Store view id => website id. */
    private const STORES = [1 => self::WEBSITE_ID, 2 => self::WEBSITE_ID];

    private const TABLE = 'smaily_automation_mapping';

    private WizardStepSaver $saver;

    private Config $config;

    private Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        $scopeConfig = new DatabaseScopeConfig($this->connection, self::STORES);
        $this->config = $this->objectManager->create(Config::class, ['scopeConfig' => $scopeConfig]);
        // The saver reads the configuration as a request does: as it was
        // when the request first read it, until the save cleans the cache.
        $requestScopeConfig = new RequestCachedScopeConfig($this->connection, self::STORES);
        $requestConfig = $this->objectManager->create(Config::class, ['scopeConfig' => $requestScopeConfig]);
        $cacheTypeList = $this->createMock(TypeListInterface::class);
        $cacheTypeList->method('cleanType')->willReturnCallback(
            static function () use ($requestScopeConfig): void {
                $requestScopeConfig->clear();
            }
        );
        // Smaily accepts every account it is asked about.
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->method('create')->willReturn($this->createMock(SmailyClient::class));

        $stores = [];
        foreach (array_keys(self::STORES) as $storeId) {
            $stores[$storeId] = $this->createMock(Store::class);
            $stores[$storeId]->method('getId')->willReturn($storeId);
        }
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(self::WEBSITE_ID);
        $website->method('getName')->willReturn('Main');
        $website->method('getStoreIds')->willReturn(array_keys(self::STORES));
        $website->method('getStores')->willReturn($stores);
        $website->method('getDefaultStore')->willReturn($stores[1]);
        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $storeManager->method('getWebsite')->willReturn($website);
        $storeManager->method('getWebsites')->willReturn([self::WEBSITE_ID => $website]);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn((string)self::WEBSITE_ID);
        $overrides = new WebsiteOverrides(
            $this->objectManager->get(ResourceConnection::class),
            $this->objectManager->get(WriterInterface::class),
            $storeManager,
            new SubdomainNormalizer()
        );

        $this->saver = new WizardStepSaver(
            $this->objectManager->get(WriterInterface::class),
            $this->objectManager->get(EncryptorInterface::class),
            $cacheTypeList,
            new SubdomainNormalizer(),
            new AccountResolver($storeManager, new LanguageResolver($requestScopeConfig)),
            $requestConfig,
            new WebsiteContext($storeManager, $request),
            $storeManager,
            $this->objectManager->get(MappingSaver::class),
            $this->createMock(SmailyClientProvider::class),
            new ConfigRowNormalizer(),
            new CredentialCheck($clientFactory, $requestConfig),
            $this->createMock(SetupNotice::class),
            $this->createMock(TransactionalSettingsSaver::class),
            $overrides,
            $this->createMock(StoreLayout::class),
            $this->createMock(WebsiteContacts::class)
        );
        $this->router = $this->objectManager->create(Router::class, [
            'config' => $this->config,
            'overrides' => $overrides,
        ]);

        foreach ([1 => 'et_EE', 2 => 'en_US'] as $storeId => $locale) {
            $this->insertConfig('stores', $storeId, 'general/locale/code', $locale);
        }
        // The store's one account, its workflows, and the transactional
        // emails' own account's row.
        $this->insertConfig('default', 0, Config::XML_PATH_SUBDOMAIN, 'old-shop');
        $this->insertConfig('default', 0, Config::XML_PATH_USERNAME, 'old-user');
        $this->insertConfig('default', 0, Config::XML_PATH_PASSWORD, $this->encrypt('old-secret'));
        $this->insertConfig('default', 0, Config::XML_PATH_MULTILINGUAL_MODE, 'single');
        $this->insertConfig('default', 0, Config::XML_PATH_WELCOME_WORKFLOW, '11');
        $this->insertConfig('default', 0, Config::XML_PATH_FIRST_ORDER_WORKFLOW, '12');
        $this->insertRow(0, 'welcome', 'et', 'default', 21, true);
        $this->insertRow(self::WEBSITE_ID, 'order_confirmation', 'default', 'transactional', 31, true);
    }

    public function testAnotherSmailyAccountRemovesTheWorkflowsPickedFromTheSavedOne(): void
    {
        self::assertSame(11, $this->router->resolve('welcome', self::WEBSITE_ID, '')?->workflowId);

        self::assertSame([], $this->saveSingle('new-shop', 'new-user', 'new-secret'));

        self::assertTrue($this->saver->isWorkflowsRemoved());
        foreach (['welcome', 'first_order', 'abandoned_cart'] as $trigger) {
            self::assertNull($this->router->resolve($trigger, self::WEBSITE_ID, 'et'), $trigger);
        }
        self::assertSame(
            [['order_confirmation', 'transactional', 31]],
            $this->rows(),
            'The transactional emails\' own account keeps its workflows'
        );
    }

    /**
     * Another API user of the same Smaily account lists the same workflows.
     */
    public function testAnotherApiUserOfTheSameAccountKeepsTheWorkflows(): void
    {
        self::assertSame([], $this->saveSingle('OLD-SHOP', 'other-user', 'other-secret'));

        self::assertFalse($this->saver->isWorkflowsRemoved());
        self::assertSame(11, $this->router->resolve('welcome', self::WEBSITE_ID, '')?->workflowId);
        self::assertSame(12, $this->router->resolve('first_order', self::WEBSITE_ID, '')?->workflowId);
    }

    /**
     * The case the PrestaShop connector showed: per-language accounts put
     * the default fallback account in the place of the store's account; back
     * on one account, that account must not start the first account's
     * workflows.
     */
    public function testPerLanguageAccountsAndBackNeverSendTheFirstAccountsWorkflowsThroughTheFallbackAccount(): void
    {
        self::assertSame([], $this->savePerLanguage(
            [$this->account('et', 'old-shop', 'old-user', ''), $this->account('en', 'en-shop', 'en-user', 'en-secret')],
            'en'
        ));
        self::assertTrue($this->saver->isWorkflowsRemoved());

        self::assertSame([], $this->saveSingle('en-shop', 'en-user', ''));

        self::assertSame('en-shop', $this->config->getWebsiteSubdomain(self::WEBSITE_ID));
        foreach (['welcome', 'first_order'] as $trigger) {
            self::assertNull($this->router->resolve($trigger, self::WEBSITE_ID, 'et'), $trigger);
        }
    }

    /**
     * With the store's account as the default fallback account nothing is
     * replaced; the workflows picked for per-language accounts in an earlier
     * mode A go, because leaving it removed those accounts.
     */
    public function testPerLanguageAccountsWithTheSavedAccountAsFallbackKeepItsWorkflows(): void
    {
        $this->insertRow(self::WEBSITE_ID, 'welcome', 'en', 'en', 41, false);

        self::assertSame([], $this->savePerLanguage(
            [$this->account('et', 'old-shop', 'old-user', ''), $this->account('en', 'en-shop', 'en-user', 'en-secret')],
            'et'
        ));

        self::assertTrue($this->saver->isWorkflowsRemoved());
        self::assertSame(11, $this->config->getWelcomeWorkflow(self::WEBSITE_ID));
        self::assertSame(
            [['welcome', 'default', 21], ['order_confirmation', 'transactional', 31]],
            $this->rows()
        );
    }

    /**
     * In mode A, another account for one language removes that language's
     * workflows only; its contacts then get the default fallback account's.
     */
    public function testAnotherAccountForOneLanguageRemovesOnlyThatLanguagesWorkflows(): void
    {
        $accounts = [
            $this->account('et', 'old-shop', 'old-user', ''),
            $this->account('en', 'en-shop', 'en-user', 'en-secret'),
        ];
        self::assertSame([], $this->savePerLanguage($accounts, 'et'));
        $this->insertRow(self::WEBSITE_ID, 'welcome', 'et', 'et', 51, false);
        $this->insertRow(self::WEBSITE_ID, 'welcome', 'en', 'en', 52, false);
        self::assertSame(52, $this->router->resolve('welcome', self::WEBSITE_ID, 'en')?->workflowId);

        $accounts[0]['password'] = '';
        $accounts[1] = $this->account('en', 'en2-shop', 'en-user', 'en2-secret');
        self::assertSame([], $this->savePerLanguage($accounts, 'et'));

        self::assertTrue($this->saver->isWorkflowsRemoved());
        $match = $this->router->resolve('welcome', self::WEBSITE_ID, 'en');
        self::assertSame([51, 'et'], [$match?->workflowId, $match?->accountKey]);
        self::assertSame(51, $this->router->resolve('welcome', self::WEBSITE_ID, 'et')?->workflowId);

        // The same accounts saved again remove nothing.
        $accounts[1]['password'] = '';
        self::assertSame([], $this->savePerLanguage($accounts, 'et'));
        self::assertFalse($this->saver->isWorkflowsRemoved());
    }

    /**
     * @return array<int, array{field: string, message: string}>
     */
    private function saveSingle(string $subdomain, string $username, string $password): array
    {
        return $this->saver->save('connect', [
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
            'multilingual_mode' => 'single',
        ]);
    }

    /**
     * A mode-A save as the Connection panel posts it: the fallback account's
     * credentials are also the top-level ones.
     *
     * @param array<int, array<string, string>> $accounts
     * @return array<int, array{field: string, message: string}>
     */
    private function savePerLanguage(array $accounts, string $fallback): array
    {
        $fallbackAccount = array_values(array_filter(
            $accounts,
            static fn (array $account): bool => $account['language'] === $fallback
        ))[0];

        return $this->saver->save('connect', [
            'subdomain' => $fallbackAccount['subdomain'],
            'username' => $fallbackAccount['username'],
            'password' => $fallbackAccount['password'],
            'multilingual_mode' => 'a',
            'fallback_language' => $fallback,
            'accounts' => $accounts,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function account(string $language, string $subdomain, string $username, string $password): array
    {
        return ['language' => $language, 'subdomain' => $subdomain, 'username' => $username, 'password' => $password];
    }

    /**
     * The mapping rows that hold a workflow: trigger, account key, workflow.
     *
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => [$row['trigger_type'], $row['account_key'], (int)$row['workflow_id']],
            $this->connection->fetchAll(
                $this->connection->select()->from(self::TABLE)->where('workflow_id > 0')->order('id')
            )
        );
    }

    private function insertRow(
        int $websiteId,
        string $trigger,
        string $language,
        string $accountKey,
        int $workflowId,
        bool $fallback
    ): void {
        $this->connection->insert(self::TABLE, [
            'website_id' => $websiteId,
            'trigger_type' => $trigger,
            'language' => $language,
            'account_key' => $accountKey,
            'workflow_id' => $workflowId,
            'is_default_fallback' => $fallback ? 1 : 0,
        ]);
    }

    private function insertConfig(string $scope, int $scopeId, string $path, string $value): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => $path,
            'value' => $value,
        ]);
    }

    private function encrypt(string $value): string
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);

        return $encryptor->encrypt($value);
    }
}
