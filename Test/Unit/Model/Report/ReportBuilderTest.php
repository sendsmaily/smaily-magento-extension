<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Report;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Automation\WorkflowMatch;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ModuleInfo;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\ReportBuilder;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * CON-234: the installation report body, as contract installation-report-v1
 * (v1.0.1) shapes it for product `connect_magento` — one connection per
 * website with a connected Smaily account (per language account in mode A),
 * one per website with a Transactional account, the main account (the
 * default scope's) authenticating it, and nothing the contract does not
 * name: no customer, order or admin data, no credential, codes only.
 */
class ReportBuilderTest extends TestCase
{
    protected const TENANT = '6d1f0c3e-2a7b-4c59-8e14-b9a0f3d5c217';
    protected const SECOND_TENANT = '0b7e4f2a-9c1d-4e8b-a3f6-5d2c8e1b7a90';
    protected const NOW = 1_762_336_803; // 2025-11-05T10:00:03Z

    /** @var array<int, array{code: string, url: string, stores: array<int, string>}> website id => website */
    protected array $websites = [1 => ['code' => 'base', 'url' => 'https://example-store.test/', 'stores' => [1 => 'et']]];

    /** @var array<int, array{string, string, string}> website id (0: default scope) => [subdomain, user, password] */
    protected array $accounts = [0 => ['example', 'api-user', 'not-a-real-password']];

    /** @var array<int, array{string, string, string}> store id => its own account (mode A) */
    protected array $storeAccounts = [];

    /** @var array<int, array{string, string, string}> website id (0: all websites) => transactional account */
    protected array $transactional = [];

    /** @var array<int, string> website id => multilingual mode */
    protected array $modes = [];

    /** @var array<int, string[]> website id => switched-on features of the website (sync, triggers) */
    protected array $switches = [1 => ['sync', Trigger::WELCOME, Trigger::ABANDONED_CART]];

    /** @var array<string, WorkflowMatch|null|\Exception> "trigger|website|language" => what the router answers */
    protected array $mapped = [];

    /** Whether the store-wide engine store is connected. */
    protected bool $engineConnected = true;

    protected bool $engineRefused = false;

    protected bool $browseTracking = true;

    protected bool $separateStores = false;

    /** @var array<int, array{tenant: string, account: string}> website id => its own engine connection */
    protected array $websiteEngines = [];

    /** @var array<string, array{status: string, last_sync_at: ?int, last_error_code: ?string}> */
    protected array $smailyHealth = [
        'example' => ['status' => 'ok', 'last_sync_at' => 1_762_336_080, 'last_error_code' => null],
    ];

    /** @var array<string, array{status: string, last_sync_at: ?int, last_error_code: ?string}> */
    protected array $engineHealth = [
        self::TENANT => ['status' => 'ok', 'last_sync_at' => 1_762_336_210, 'last_error_code' => null],
    ];

    public function testOneWebsiteSendsTheContractsShape(): void
    {
        $report = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, ReportBuilder::TRIGGER_SETUP_SAVED);

        self::assertNotNull($report);
        self::assertSame([
            'product' => 'connect_magento',
            'product_version' => ModuleInfo::VERSION,
            'platforms' => [['name' => 'magento', 'version' => '2.4.7-p3']],
            'php_version' => substr(PHP_VERSION, 0, 32),
            'installation_url' => 'https://example-store.test',
            'smaily_subdomain' => 'example',
            'event' => 'snapshot',
            'trigger' => 'setup_saved',
            'sent_at' => '2025-11-05T10:00:03Z',
            'connections' => [[
                'role' => 'marketing',
                'store_url' => 'https://example-store.test',
                'website' => 'base',
                'smaily_subdomain' => 'example',
                'features' => [
                    'subscriber_sync',
                    'welcome_automation',
                    'abandoned_cart',
                    'campaign_intelligence',
                    'browse_tracking',
                ],
                'smaily' => ['status' => 'ok', 'last_sync_at' => '2025-11-05T09:48:00Z', 'last_error_code' => null],
                'intelligence' => [
                    'tenant_id' => self::TENANT,
                    'connected' => true,
                    'status' => 'ok',
                    'last_sync_at' => '2025-11-05T09:50:10Z',
                    'last_error_code' => null,
                ],
            ]],
        ], $report->body);
        self::assertEquals(new Credentials('example', 'api-user', 'not-a-real-password'), $report->credentials);
    }

    public function testTheBodyCarriesNoCredentialNorEngineKey(): void
    {
        $json = (string)json_encode($this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body);

        self::assertStringNotContainsString('api-user', $json);
        self::assertStringNotContainsString('not-a-real-password', $json);
        self::assertStringNotContainsString('sk_live', $json);
        self::assertStringNotContainsString('@', $json);
    }

    public function testNothingIsReportedWithoutAConnectedMarketingAccount(): void
    {
        $this->accounts = [];
        $this->transactional = [0 => ['example-transactional', 'api-user', 'secret']];

        $builder = $this->builder();

        self::assertNull($builder->mainCredentials());
        self::assertNull($builder->build(ReportBuilder::EVENT_SNAPSHOT, 'daily'));
        self::assertNull($builder->build(ReportBuilder::EVENT_DEACTIVATED));
    }

    /**
     * @dataProvider incompleteAccounts
     * @param array{string, string, string} $account
     */
    public function testAnIncompleteOrInvalidAccountIsNotConnected(array $account): void
    {
        $this->accounts = [0 => $account];

        self::assertNull($this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily'));
    }

    /**
     * @return array<string, array{array{string, string, string}}>
     */
    public static function incompleteAccounts(): array
    {
        return [
            'no password' => [['example', 'api-user', '']],
            'no user' => [['example', '', 'secret']],
            'no subdomain' => [['', 'api-user', 'secret']],
            'a subdomain outside the rule' => [['exa_mple', 'api-user', 'secret']],
        ];
    }

    public function testASubdomainSavedAsAnAddressIsReportedBare(): void
    {
        $this->accounts = [0 => ['https://Example.sendsmaily.net/', 'api-user', 'secret']];

        self::assertSame('example', $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['smaily_subdomain']);
    }

    /**
     * The contract's Magento example: two websites with their own accounts;
     * the default scope's account authenticates, the other is declared; one
     * engine store serves both under one store.
     */
    public function testEachWebsiteIsAConnectionAndTheDefaultScopesAccountIsTheMain(): void
    {
        $this->websites[2] = ['code' => 'second', 'url' => 'https://second-example-store.test/', 'stores' => [2 => 'en']];
        $this->accounts[2] = ['example-second', 'second-user', 'second-secret'];
        $this->switches[2] = ['sync'];
        $this->smailyHealth['example-second'] = [
            'status' => 'failing',
            'last_sync_at' => null,
            'last_error_code' => 'credentials_rejected',
        ];

        $report = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily');

        self::assertSame('example', $report?->body['smaily_subdomain']);
        self::assertSame('api-user', $report->credentials->username);
        $second = $report->body['connections'][1];
        self::assertSame('second', $second['website']);
        self::assertSame('https://second-example-store.test', $second['store_url']);
        self::assertSame('example-second', $second['smaily_subdomain']);
        self::assertSame(['subscriber_sync', 'campaign_intelligence', 'browse_tracking'], $second['features']);
        self::assertSame(
            ['status' => 'failing', 'last_sync_at' => null, 'last_error_code' => 'credentials_rejected'],
            $second['smaily']
        );
        self::assertSame(self::TENANT, $second['intelligence']['tenant_id']);
    }

    public function testWithoutADefaultScopeAccountTheFirstConnectionAuthenticates(): void
    {
        $this->websites[2] = ['code' => 'second', 'url' => 'https://second.test/', 'stores' => [2 => 'en']];
        $this->accounts = [
            1 => ['first', 'first-user', 'first-secret'],
            2 => ['second', 'second-user', 'second-secret'],
        ];

        $report = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily');

        self::assertSame('first', $report?->body['smaily_subdomain']);
        self::assertSame('first-user', $report->credentials->username);
    }

    public function testADefaultScopeAccountNoWebsiteUsesIsNotTheMain(): void
    {
        $this->accounts[1] = ['own', 'own-user', 'own-secret'];

        $report = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily');

        self::assertSame('own', $report?->body['smaily_subdomain']);
        self::assertSame(['own'], array_column($report->body['connections'], 'smaily_subdomain'));
    }

    public function testModeAReportsEachLanguageAccountWithItsOwnWorkflowsOnly(): void
    {
        $this->modes[1] = 'a';
        $this->websites[1]['stores'] = [1 => 'et', 2 => 'en', 3 => 'fil_PH'];
        $this->storeAccounts = [
            1 => ['example', 'api-user', 'not-a-real-password'],
            2 => ['example-en', 'en-user', 'en-secret'],
            3 => ['example-fil', 'fil-user', 'fil-secret'],
        ];
        $this->mapped = [
            'welcome|1|et' => new WorkflowMatch(11, 'et', 'et'),
            // The fallback language's workflow: another account's, not en's.
            'welcome|1|en' => new WorkflowMatch(11, 'et', 'et'),
            'abandoned_cart|1|et' => null,
            'abandoned_cart|1|en' => new WorkflowMatch(12, 'en', 'en'),
        ];

        $connections = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['connections'];

        self::assertSame(['et', 'en'], array_column($connections, 'language'), 'A language outside ISO 639-1 is left out.');
        self::assertSame(['example', 'example-en'], array_column($connections, 'smaily_subdomain'));
        self::assertSame(['subscriber_sync', 'welcome_automation', 'campaign_intelligence', 'browse_tracking'], $connections[0]['features']);
        self::assertSame(['subscriber_sync', 'abandoned_cart', 'campaign_intelligence', 'browse_tracking'], $connections[1]['features']);
    }

    public function testAnAutomationCountsOnlyWhenSwitchedOnAndMapped(): void
    {
        $this->switches[1] = [Trigger::WELCOME, Trigger::FIRST_ORDER];
        $this->mapped = [
            'welcome|1|et' => null,
            'first_order|1|et' => new \RuntimeException('the mapping table is gone'),
        ];

        $features = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['connections'][0]['features'];

        self::assertSame(['campaign_intelligence', 'browse_tracking'], $features);
    }

    public function testATransactionalAccountIsADeclaredConnectionPerWebsite(): void
    {
        $this->transactional = [0 => ['example-transactional', 'tx-user', 'tx-secret']];

        $report = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily');

        self::assertSame('example', $report?->body['smaily_subdomain']);
        self::assertSame([
            'role' => 'transactional',
            'store_url' => 'https://example-store.test',
            'website' => 'base',
            'smaily_subdomain' => 'example-transactional',
            // No order or shipping email goes through Smaily yet.
            'features' => [],
            'smaily' => ['status' => 'ok', 'last_sync_at' => null, 'last_error_code' => null],
        ], $report->body['connections'][1]);
    }

    public function testARefusedEngineStoreIsReportedNotConnected(): void
    {
        $this->engineRefused = true;
        $this->engineHealth[self::TENANT] = ['status' => 'failing', 'last_sync_at' => null, 'last_error_code' => 'tenant_inactive'];

        $intelligence = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['connections'][0]['intelligence'];

        self::assertFalse($intelligence['connected']);
        self::assertSame('failing', $intelligence['status']);
        self::assertSame('tenant_inactive', $intelligence['last_error_code']);
    }

    public function testWithoutAnEngineStoreThereIsNoIntelligence(): void
    {
        $this->engineConnected = false;

        $connection = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['connections'][0];

        self::assertArrayNotHasKey('intelligence', $connection);
        self::assertSame(['subscriber_sync', 'welcome_automation', 'abandoned_cart'], $connection['features']);
    }

    public function testSeparateStoresNameEachWebsitesOwnEngineStore(): void
    {
        $this->separateStores = true;
        $this->websites[2] = ['code' => 'second', 'url' => 'https://second.test/', 'stores' => [2 => 'en']];
        $this->websites[3] = ['code' => 'third', 'url' => 'https://third.test/', 'stores' => [3 => 'en']];
        $this->accounts[1] = ['example', 'api-user', 'not-a-real-password'];
        $this->accounts[2] = ['example-second', 'second-user', 'second-secret'];
        $this->accounts[3] = ['example-third', 'third-user', 'third-secret'];
        $this->websiteEngines = [2 => ['tenant' => self::SECOND_TENANT, 'account' => 'example-second']];

        $connections = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['connections'];

        self::assertSame(self::TENANT, $connections[0]['intelligence']['tenant_id']);
        self::assertSame(self::SECOND_TENANT, $connections[1]['intelligence']['tenant_id']);
        self::assertArrayNotHasKey('intelligence', $connections[2], 'No engine store has the third account.');
    }

    public function testADisconnectedEngineStoreLeavesTheConnectionsWithoutIt(): void
    {
        $report = $this->builder()->build(ReportBuilder::EVENT_DISCONNECTED, '', [self::TENANT]);

        self::assertSame('disconnected', $report?->body['event']);
        self::assertArrayNotHasKey('trigger', $report->body);
        $connection = $report->body['connections'][0];
        self::assertArrayNotHasKey('intelligence', $connection);
        self::assertSame(['subscriber_sync', 'welcome_automation', 'abandoned_cart'], $connection['features']);
    }

    public function testTheLastAccountGoingIsReportedWithThePreviousMainAccount(): void
    {
        $this->accounts = [];
        $previous = new Credentials('example', 'api-user', 'not-a-real-password');

        $builder = $this->builder();
        $report = $builder->build(ReportBuilder::EVENT_DISCONNECTED, '', [], $previous);

        self::assertSame('example', $report?->body['smaily_subdomain']);
        self::assertSame([], $report->body['connections']);
        self::assertSame($previous, $report->credentials);
        self::assertNull($builder->build(ReportBuilder::EVENT_DISCONNECTED), 'Without credentials nothing is sent.');
        self::assertNull($builder->build(ReportBuilder::EVENT_SNAPSHOT, 'daily', [], $previous));
    }

    public function testADeactivationCarriesTheConnectionsAsTheyAre(): void
    {
        $report = $this->builder()->build(ReportBuilder::EVENT_DEACTIVATED);

        self::assertSame('deactivated', $report?->body['event']);
        self::assertArrayNotHasKey('trigger', $report->body);
        self::assertArrayHasKey('intelligence', $report->body['connections'][0]);
    }

    public function testAnInstallationWithoutAValidAddressReportsNothing(): void
    {
        $this->websites[1]['url'] = 'https://user:pass@example-store.test/';

        self::assertNull($this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily'));
    }

    public function testAWebsiteWithoutAValidAddressIsLeftOut(): void
    {
        $this->websites[2] = ['code' => 'second', 'url' => 'https://second.test/?x=1', 'stores' => [2 => 'en']];

        $connections = $this->builder()->build(ReportBuilder::EVENT_SNAPSHOT, 'daily')?->body['connections'];

        self::assertSame(['base'], array_column($connections, 'website'));
    }

    public function testTheAccountKeysNameEachRoleAndAccount(): void
    {
        $this->websites[2] = ['code' => 'second', 'url' => 'https://second.test/', 'stores' => [2 => 'en']];
        $this->transactional = [0 => ['example-transactional', 'tx-user', 'tx-secret']];

        self::assertSame(
            ['marketing|example', 'transactional|example-transactional'],
            $this->builder()->accountKeys()
        );
    }

    protected function builder(): ReportBuilder
    {
        return new ReportBuilder(
            $this->config(),
            $this->engine(),
            $this->storeManager(),
            $this->productMetadata(),
            $this->storeLayout(),
            new SubdomainNormalizer(),
            $this->accountResolver(),
            $this->router(),
            $this->health(),
            $this->clock()
        );
    }

    private function config(): Config
    {
        $config = $this->createMock(Config::class);
        $account = fn (int $id, int $part): string => $this->accounts[$id][$part] ?? $this->accounts[0][$part] ?? '';
        $config->method('getWebsiteSubdomain')->willReturnCallback(fn (int $id): string => $account($id, 0));
        $config->method('getWebsiteUsername')->willReturnCallback(fn (int $id): string => $account($id, 1));
        $config->method('getWebsitePassword')->willReturnCallback(fn (int $id): string => $account($id, 2));
        $config->method('getSubdomain')->willReturnCallback(fn ($id): string => $this->storeAccounts[(int)$id][0] ?? '');
        $config->method('getUsername')->willReturnCallback(fn ($id): string => $this->storeAccounts[(int)$id][1] ?? '');
        $config->method('getPassword')->willReturnCallback(fn ($id): string => $this->storeAccounts[(int)$id][2] ?? '');
        $transactional = fn (?int $id, int $part): string
            => $this->transactional[(int)$id][$part] ?? $this->transactional[0][$part] ?? '';
        $config->method('getTransactionalSubdomain')->willReturnCallback(fn (?int $id): string => $transactional($id, 0));
        $config->method('getTransactionalUsername')->willReturnCallback(fn (?int $id): string => $transactional($id, 1));
        $config->method('getTransactionalPassword')->willReturnCallback(fn (?int $id): string => $transactional($id, 2));
        $config->method('getMultilingualMode')->willReturnCallback(fn (?int $id): string => $this->modes[(int)$id] ?? 'single');
        $switch = fn (string $name): \Closure => fn (?int $id): bool => in_array($name, $this->switches[(int)$id] ?? [], true);
        $config->method('isContactSyncActive')->willReturnCallback($switch('sync'));
        $config->method('isWelcomeEnabled')->willReturnCallback($switch(Trigger::WELCOME));
        $config->method('isFirstOrderEnabled')->willReturnCallback($switch(Trigger::FIRST_ORDER));
        $config->method('isAbandonedCartEnabled')->willReturnCallback($switch(Trigger::ABANDONED_CART));

        return $config;
    }

    private function engine(): EngineSettings
    {
        $primary = $this->engineStore($this->engineConnected, self::TENANT, 'example');
        $primary->method('withWebsite')->willReturnCallback(function (int $websiteId): EngineSettings {
            $own = $this->websiteEngines[$websiteId] ?? null;
            $settings = $this->engineStore($own !== null, $own['tenant'] ?? '', $own['account'] ?? '');
            $settings->method('isWebsiteConnection')->willReturn($own !== null);

            return $settings;
        });

        return $primary;
    }

    private function engineStore(bool $connected, string $tenant, string $account): EngineSettings&MockObject
    {
        $settings = $this->createMock(EngineSettings::class);
        $settings->method('isConnected')->willReturn($connected);
        $settings->method('isSendingAllowed')->willReturn($connected && !$this->engineRefused);
        $settings->method('getTenantId')->willReturn($connected ? $tenant : '');
        $settings->method('getSmailySubdomain')->willReturn($account);
        $settings->method('isBrowseTrackingEnabled')->willReturn($connected && $this->browseTracking);

        return $settings;
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ($this->websites as $id => $data) {
            $store = $this->createMock(Store::class);
            $store->method('isFrontUrlSecure')->willReturn(true);
            $store->method('getBaseUrl')->willReturnCallback(
                static fn (string $type = UrlInterface::URL_TYPE_LINK, ?bool $secure = null): string
                    => $type === UrlInterface::URL_TYPE_WEB && $secure === true ? $data['url'] : 'wrong://'
            );
            $website = $this->createMock(Website::class);
            $website->method('getId')->willReturn((string)$id);
            $website->method('getCode')->willReturn($data['code']);
            $website->method('getDefaultStore')->willReturn($store);
            $websites[$id] = $website;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn(array_reverse($websites, true));
        $storeManager->method('getWebsite')->willReturnCallback(static fn (int $id): Website => $websites[$id]);
        $storeManager->method('getDefaultStoreView')->willReturnCallback(
            static fn (): Store => $websites[array_key_first($websites)]->getDefaultStore()
        );

        return $storeManager;
    }

    private function productMetadata(): ProductMetadataInterface
    {
        $metadata = $this->createMock(ProductMetadataInterface::class);
        $metadata->method('getVersion')->willReturn('2.4.7-p3');

        return $metadata;
    }

    private function storeLayout(): StoreLayout
    {
        $layout = $this->createMock(StoreLayout::class);
        $layout->method('isSeparateStores')->willReturn($this->separateStores);

        return $layout;
    }

    private function accountResolver(): AccountResolver
    {
        $resolver = $this->createMock(AccountResolver::class);
        $language = static fn (string $locale): string => strtolower((string)strtok($locale, '_'));
        $resolver->method('detectedLanguages')->willReturnCallback(
            fn (int $websiteId): array => array_values(array_unique(array_map(
                $language,
                $this->websites[$websiteId]['stores'] ?? []
            )))
        );
        $resolver->method('storeIdsForAccountKey')->willReturnCallback(
            fn (string $key, int $websiteId): array => array_keys(array_filter(
                $this->websites[$websiteId]['stores'] ?? [],
                static fn (string $locale): bool => $language($locale) === $key
            ))
        );
        $resolver->method('defaultLanguage')->willReturnCallback(
            fn (int $websiteId): string => $language((string)reset($this->websites[$websiteId]['stores']))
        );

        return $resolver;
    }

    private function router(): Router
    {
        $router = $this->createMock(Router::class);
        $router->method('resolve')->willReturnCallback(
            function (string $trigger, int $websiteId, string $language): ?WorkflowMatch {
                $key = $trigger . '|' . $websiteId . '|' . $language;
                if (!array_key_exists($key, $this->mapped)) {
                    return new WorkflowMatch(7);
                }
                $answer = $this->mapped[$key];
                if ($answer instanceof \Exception) {
                    throw $answer;
                }

                return $answer;
            }
        );

        return $router;
    }

    private function health(): ConnectionHealth
    {
        $none = ['status' => 'ok', 'last_sync_at' => null, 'last_error_code' => null];
        $health = $this->createMock(ConnectionHealth::class);
        $health->method('smaily')->willReturnCallback(fn (string $subdomain): array => $this->smailyHealth[$subdomain] ?? $none);
        $health->method('engine')->willReturnCallback(fn (string $tenant): array => $this->engineHealth[$tenant] ?? $none);

        return $health;
    }

    private function clock(): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtTimestamp')->willReturn(static::NOW);

        return $dateTime;
    }
}
