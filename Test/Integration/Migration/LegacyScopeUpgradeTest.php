<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Migration;

use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Setup\Patch\Data\MigrateLegacyConfig;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * An upgrade of a multi-website 2.8.x store: one Smaily account at the
 * default scope for four websites and eleven store views, per-website
 * overrides, and rows at store-view scope. After the migration, each
 * website and store view is read through the real v3 getters, with
 * Magento's scope fallback (store view -> website -> default -> config.xml).
 *
 * 2.8.x showed every setting at the default and website scope only and read
 * every setting at the website scope; a store-view row could come only from
 * `config:set --scope=stores`, and 2.8.x never read it. See docs/UPGRADING.md.
 */
class LegacyScopeUpgradeTest extends IntegrationTestCase
{
    /**
     * Store view id => [website id, locale].
     */
    private const STORES = [
        1 => [1, 'et_EE'], 2 => [1, 'en_US'], 3 => [1, 'ru_RU'],
        4 => [2, 'lv_LV'], 5 => [2, 'en_US'], 6 => [2, 'ru_RU'],
        7 => [3, 'lt_LT'], 8 => [3, 'en_US'], 9 => [3, 'ru_RU'],
        10 => [4, 'fi_FI'], 11 => [4, 'en_US'],
    ];

    /**
     * @var Config v3 config read over the migrated rows
     */
    private Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed('default', 0, [
            'smaily/general/enable' => '1',
            'smaily/general/subdomain' => 'shop',
            'smaily/general/username' => 'api-user',
            'smaily/general/password' => 'plain-secret',
            'smaily/subscribe/enableNewsletterSubscriptions' => '1',
            'smaily/subscribe/workflowId' => '55',
            'smaily/sync/enableCronSync' => '1',
            'smaily/sync/fields' => 'first_name,last_name,gender',
            'smaily/abandoned/enableAbandonedCart' => '1',
            'smaily/abandoned/autoresponderId' => '77',
            'smaily/abandoned/syncTime' => '2:hour',
        ]);
        // Website overrides, as the 2.8.x admin saves them.
        $this->seed('websites', 2, [
            'smaily/subscribe/workflowId' => '66',
            'smaily/abandoned/syncTime' => '1:hour',
        ]);
        $this->seed('websites', 3, ['smaily/abandoned/enableAbandonedCart' => '0']);
        $this->seed('websites', 4, ['smaily/general/enable' => '0']);
        // Store-view rows: only `config:set --scope=stores` writes them.
        $this->seed('stores', 3, [
            'smaily/general/username' => 'stale-user',
            'smaily/sync/fields' => 'first_name',
            'smaily/abandoned/enableAbandonedCart' => '0',
        ]);
        foreach (self::STORES as $storeId => [, $locale]) {
            $this->seed('stores', $storeId, ['general/locale/code' => $locale]);
        }

        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->objectManager->create(MigrateLegacyConfig::class, [
            'moduleDataSetup' => new DataSetup($resourceConnection),
        ])->apply();

        $this->config = $this->objectManager->create(Config::class, ['scopeConfig' => $this->configScope()]);
    }

    public function testTheDefaultScopeAccountServesEveryStoreViewOfEveryWebsite(): void
    {
        foreach (array_keys(self::STORES) as $storeId) {
            if ($storeId === 3) {
                continue; // The store-view row, below.
            }
            self::assertTrue($this->config->isConnected($storeId), 'Store view ' . $storeId);
            self::assertSame('shop', $this->config->getSubdomain($storeId));
            self::assertSame('api-user', $this->config->getUsername($storeId));
            self::assertSame('plain-secret', $this->config->getPassword($storeId));
        }
    }

    public function testWebsiteOverridesWinOverTheDefaultScope(): void
    {
        self::assertSame(55, $this->config->getWelcomeWorkflow(1));
        self::assertSame(66, $this->config->getWelcomeWorkflow(2));
        self::assertSame(55, $this->config->getWelcomeWorkflow(3));
        foreach ([1, 2, 3, 4] as $websiteId) {
            self::assertTrue($this->config->isWelcomeEnabled($websiteId));
            self::assertTrue($this->config->isSyncEnabled($websiteId));
            self::assertSame(77, $this->config->getAbandonedCartWorkflow($websiteId));
        }

        self::assertSame(120, $this->config->getAbandonedCutoffMinutes(1));
        self::assertSame(60, $this->config->getAbandonedCutoffMinutes(2));
        self::assertTrue($this->config->isAbandonedCartEnabled(1));
        self::assertFalse($this->config->isAbandonedCartEnabled(3));
    }

    public function testAStoreViewRowIsReadForTheAccountOnlyAsItWasNeverReadBy28x(): void
    {
        // The account is read per store view in v3 (per-language accounts),
        // so the row 2.8.x ignored now replaces the user for this store view.
        self::assertSame('stale-user', $this->config->getUsername(3));
        self::assertSame('shop', $this->config->getSubdomain(3));

        // Every other setting is read per website, as in 2.8.x: the
        // store-view rows stay unread.
        self::assertSame(['first_name', 'last_name', 'user_gender'], $this->config->getSyncFields(1));
        self::assertTrue($this->config->isAbandonedCartEnabled(1));
    }

    public function testEnableModuleIsNotCarriedOverSoASwitchedOffWebsiteStartsSyncing(): void
    {
        // 2.8.x "Enable Module = No" on website 4 stopped its sync, opt-in
        // and abandoned cart. v3 has no such switch and the migration does
        // not read it: website 4 inherits the default-scope settings.
        self::assertTrue($this->config->isConnected(10));
        self::assertTrue($this->config->isSyncEnabled(4));
        self::assertTrue($this->config->isWelcomeEnabled(4));
        self::assertTrue($this->config->isAbandonedCartEnabled(4));
    }

    public function testEveryStoreViewLanguageResolvesAndRoutesToItsWebsiteWorkflowThroughTheOneAccount(): void
    {
        /** @var LanguageResolver $languages */
        $languages = $this->objectManager->create(LanguageResolver::class, [
            'scopeConfig' => $this->configScope(),
        ]);
        /** @var Router $router */
        $router = $this->objectManager->create(Router::class, ['config' => $this->config]);

        $expectedLanguage = ['et_EE' => 'et', 'en_US' => 'en', 'ru_RU' => 'ru',
            'lv_LV' => 'lv', 'lt_LT' => 'lt', 'fi_FI' => 'fi'];
        foreach (self::STORES as $storeId => [$websiteId, $locale]) {
            $language = $languages->forStore($storeId);
            self::assertSame($expectedLanguage[$locale], $language, 'Store view ' . $storeId);

            // The migration leaves the single-language mode: one workflow
            // per website for every language, with the store view's account.
            self::assertSame('single', $this->config->getMultilingualMode($websiteId));
            $welcome = $router->resolve(Trigger::WELCOME, $websiteId, $language);
            self::assertNotNull($welcome);
            self::assertSame($websiteId === 2 ? 66 : 55, $welcome->workflowId);
            self::assertNull($welcome->accountKey);
            self::assertSame(77, $router->resolve(Trigger::ABANDONED_CART, $websiteId, $language)?->workflowId);
        }
    }

    private function configScope(): DatabaseScopeConfig
    {
        return new DatabaseScopeConfig(
            $this->connection,
            array_map(static fn (array $store): int => $store[0], self::STORES)
        );
    }

    /**
     * @param array<string, string> $values path => value
     */
    private function seed(string $scope, int $scopeId, array $values): void
    {
        foreach ($values as $path => $value) {
            $this->connection->insert('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ]);
        }
    }
}
