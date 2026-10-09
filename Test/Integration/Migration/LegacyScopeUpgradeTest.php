<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Migration;

use Magento\Framework\App\ResourceConnection;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Setup\Patch\Data\MigrateLegacyConfig;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\DataSetup;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

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
 *
 * PRO-3681: Enable Module = No switches contact sync, welcome and abandoned
 * cart off at its scope (a website's own Yes under it keeps its 2.8.x
 * values), and a store-view account row is not carried over.
 *
 * PRO-4015: a website where 2.8.x had the opt-in on with no Autoresponder
 * ID keeps the welcome off, and an admin notice names it.
 */
class LegacyScopeUpgradeTest extends IntegrationTestCase
{
    private const NOTICE_TITLE = 'Smaily Connect upgrade: store-view Smaily account not carried over';

    private const WELCOME_NOTICE_TITLE = 'Smaily Connect upgrade: the welcome automation has no workflow';

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
        $this->seed('stores', 2, ['smaily/general/enable' => '0']);
        foreach (self::STORES as $storeId => [, $locale]) {
            $this->seed('stores', $storeId, ['general/locale/code' => $locale]);
        }

        $this->migrate();
    }

    protected function tearDown(): void
    {
        StoreLocale::reset();
        parent::tearDown();
    }

    public function testTheDefaultScopeAccountServesEveryStoreViewOfEveryWebsite(): void
    {
        foreach (array_keys(self::STORES) as $storeId) {
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
            self::assertSame(77, $this->config->getAbandonedCartWorkflow($websiteId));
        }

        self::assertSame(120, $this->config->getAbandonedCutoffMinutes(1));
        self::assertSame(60, $this->config->getAbandonedCutoffMinutes(2));
        self::assertTrue($this->config->isAbandonedCartEnabled(1));
        self::assertFalse($this->config->isAbandonedCartEnabled(3));
    }

    public function testAStoreViewAccountRowIsNotCarriedOverAndTheAdminNoticeNamesTheStoreView(): void
    {
        // v3 reads the account per store view (per-language accounts), so
        // the row 2.8.x ignored is left out: store view 3 keeps its
        // website's account.
        self::assertSame('api-user', $this->config->getUsername(3));
        self::assertSame([], $this->storeViewRows(3, Config::XML_PATH_USERNAME));

        self::assertSame(
            [
                'The Smaily subdomain, API username or API password saved for these store views was not'
                . ' carried over, because Smaily for Magento 2.8.x did not use it: Store view 3 (Website 1).'
                . ' These store views use their website\'s Smaily account, as they did in 2.8.x.',
            ],
            $this->noticeDescriptions(self::NOTICE_TITLE)
        );
    }

    public function testTheStoreViewAccountNoticeIsInEstonianInAnEstonianAdmin(): void
    {
        $this->env->resetState();
        $this->seed('stores', 5, ['smaily/general/password' => 'stale-secret']);
        StoreLocale::use('et_EE');

        $this->migrate();

        self::assertSame(
            [
                'Nende poevaadete jaoks salvestatud Smaily alamdomeeni, API kasutajanime ega API parooli üle'
                . ' ei toodud, sest Smaily for Magento 2.8.x neid ei kasutanud: Store view 5 (Website 2).'
                . ' Need poevaated kasutavad oma veebisaidi Smaily kontot, nagu 2.8.x-is.',
            ],
            $this->noticeDescriptions('Smaily Connecti uuendus: poevaate Smaily kontot üle ei toodud')
        );
        self::assertSame([], $this->storeViewRows(5, Config::XML_PATH_PASSWORD));
    }

    public function testOtherStoreViewRowsAreCarriedOverAtTheStoreViewAndStayUnread(): void
    {
        // Every other setting is read per website, as in 2.8.x: the
        // store-view rows are carried over and stay unread.
        self::assertSame(
            ['first_name'],
            array_column($this->storeViewRows(3, Config::XML_PATH_SYNC_FIELDS), 'value')
        );
        self::assertSame(['first_name', 'last_name', 'user_gender'], $this->config->getSyncFields(1));
        self::assertTrue($this->config->isAbandonedCartEnabled(1));
        // A store-view Enable Module row is not carried over.
        self::assertSame([], $this->storeViewRows(2, Config::XML_PATH_SYNC_ENABLED));
        self::assertTrue($this->config->isSyncEnabled(1));
    }

    public function testAWebsiteWithEnableModuleNoHasSyncWelcomeAndAbandonedCartOff(): void
    {
        // 2.8.x "Enable Module = No" on website 4 stopped its sync, opt-in
        // and abandoned cart; the upgrade switches the three off there.
        self::assertTrue($this->config->isConnected(10));
        self::assertFalse($this->config->isSyncEnabled(4));
        self::assertFalse($this->config->isWelcomeEnabled(4));
        self::assertFalse($this->config->isAbandonedCartEnabled(4));
        // The default scope has Yes: no other website is switched off.
        foreach ([1, 2, 3] as $websiteId) {
            self::assertTrue($this->config->isSyncEnabled($websiteId), 'Website ' . $websiteId);
            self::assertTrue($this->config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
        }
    }

    public function testUnderADefaultScopeWithNoAWebsiteWithItsOwnYesRunsAsIn28x(): void
    {
        $this->env->resetState();
        $this->seed('default', 0, [
            'smaily/general/enable' => '0',
            'smaily/subscribe/enableNewsletterSubscriptions' => '1',
            'smaily/subscribe/workflowId' => '55',
            'smaily/sync/enableCronSync' => '1',
            'smaily/abandoned/enableAbandonedCart' => '1',
            'smaily/abandoned/autoresponderId' => '77',
        ]);
        // Website 2: its own Yes and its own values for all three.
        $this->seed('websites', 2, [
            'smaily/general/enable' => '1',
            'smaily/sync/enableCronSync' => '0',
            'smaily/abandoned/enableAbandonedCart' => '0',
            'smaily/subscribe/enableNewsletterSubscriptions' => '1',
            'smaily/subscribe/workflowId' => '66',
        ]);
        // Website 3: its own Yes; the three come from the default scope.
        $this->seed('websites', 3, ['smaily/general/enable' => '1']);

        $this->migrate();

        // Websites 1 and 4 have no own value: the default scope's No.
        foreach ([1, 4] as $websiteId) {
            self::assertFalse($this->config->isSyncEnabled($websiteId), 'Website ' . $websiteId);
            self::assertFalse($this->config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
            self::assertFalse($this->config->isAbandonedCartEnabled($websiteId), 'Website ' . $websiteId);
        }
        // Website 2 keeps its own values.
        self::assertFalse($this->config->isSyncEnabled(2));
        self::assertTrue($this->config->isWelcomeEnabled(2));
        self::assertSame(66, $this->config->getWelcomeWorkflow(2));
        self::assertFalse($this->config->isAbandonedCartEnabled(2));
        // Website 3 runs on what it inherited from the default scope in 2.8.x.
        self::assertTrue($this->config->isSyncEnabled(3));
        self::assertTrue($this->config->isWelcomeEnabled(3));
        self::assertSame(55, $this->config->getWelcomeWorkflow(3));
        self::assertTrue($this->config->isAbandonedCartEnabled(3));
    }

    public function testAWebsiteWithTheOptInOffKeepsTheWelcomeOffUnderADefaultScopeWithItOn(): void
    {
        // PRO-4009: 2.8.x read the opt-in switch per website.
        $this->env->resetState();
        $this->seed('default', 0, [
            'smaily/subscribe/enableNewsletterSubscriptions' => '1',
            'smaily/subscribe/workflowId' => '101',
        ]);
        $this->seed('websites', 1, ['smaily/subscribe/enableNewsletterSubscriptions' => '0']);
        // A store-view row: 2.8.x never read it, and v3 does not either.
        $this->seed('stores', 4, ['smaily/subscribe/enableNewsletterSubscriptions' => '0']);

        $this->migrate();

        self::assertFalse($this->config->isWelcomeEnabled(1));
        foreach ([2, 3, 4] as $websiteId) {
            self::assertTrue($this->config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
            self::assertSame(101, $this->config->getWelcomeWorkflow($websiteId));
        }
    }

    public function testAWebsiteWithTheOptInOnSendsTheDefaultWorkflowUnderADefaultScopeWithItOff(): void
    {
        $this->env->resetState();
        $this->seed('default', 0, [
            'smaily/subscribe/enableNewsletterSubscriptions' => '0',
            'smaily/subscribe/workflowId' => '101',
        ]);
        $this->seed('websites', 2, ['smaily/subscribe/enableNewsletterSubscriptions' => '1']);

        $this->migrate();

        self::assertTrue($this->config->isWelcomeEnabled(2));
        self::assertSame(101, $this->config->getWelcomeWorkflow(2));
        foreach ([1, 3, 4] as $websiteId) {
            self::assertFalse($this->config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
        }
    }

    public function testAWebsiteWithAbandonedCartOffKeepsItOffUnderADefaultScopeWithItOn(): void
    {
        // PRO-4013: 2.8.x read the abandoned-cart settings per website.
        $this->env->resetState();
        $this->seed('default', 0, [
            'smaily/abandoned/enableAbandonedCart' => '1',
            'smaily/abandoned/autoresponderId' => '77',
            'smaily/abandoned/syncTime' => '2:hour',
        ]);
        $this->seed('websites', 1, ['smaily/abandoned/enableAbandonedCart' => '0']);
        // "No automation workflow selected": 2.8.x sent no reminder there.
        $this->seed('websites', 3, ['smaily/abandoned/autoresponderId' => '']);

        $this->migrate();

        self::assertFalse($this->config->isAbandonedCartEnabled(1));
        self::assertFalse($this->config->isAbandonedCartEnabled(3));
        foreach ([2, 4] as $websiteId) {
            self::assertTrue($this->config->isAbandonedCartEnabled($websiteId), 'Website ' . $websiteId);
            self::assertSame(77, $this->config->getAbandonedCartWorkflow($websiteId));
            self::assertSame(120, $this->config->getAbandonedCutoffMinutes($websiteId));
        }
    }

    public function testAWebsiteWithAbandonedCartOnUsesTheDefaultWorkflowAndDelayUnderADefaultScopeWithItOff(): void
    {
        $this->env->resetState();
        $this->seed('default', 0, [
            'smaily/abandoned/enableAbandonedCart' => '0',
            'smaily/abandoned/autoresponderId' => '77',
            'smaily/abandoned/syncTime' => '2:hour',
        ]);
        $this->seed('websites', 2, ['smaily/abandoned/enableAbandonedCart' => '1']);
        $this->seed('websites', 3, [
            'smaily/abandoned/enableAbandonedCart' => '1',
            'smaily/abandoned/syncTime' => '1:hour',
        ]);

        $this->migrate();

        self::assertTrue($this->config->isAbandonedCartEnabled(2));
        self::assertSame(77, $this->config->getAbandonedCartWorkflow(2));
        self::assertSame(120, $this->config->getAbandonedCutoffMinutes(2));
        self::assertTrue($this->config->isAbandonedCartEnabled(3));
        self::assertSame(77, $this->config->getAbandonedCartWorkflow(3));
        self::assertSame(60, $this->config->getAbandonedCutoffMinutes(3));
        foreach ([1, 4] as $websiteId) {
            self::assertFalse($this->config->isAbandonedCartEnabled($websiteId), 'Website ' . $websiteId);
        }
    }

    public function testAWebsiteWithTheOptInOnAndNoWorkflowKeepsTheWelcomeOffAndTheNoticeNamesIt(): void
    {
        $this->env->resetState();
        $this->seed('default', 0, [
            'smaily/subscribe/enableNewsletterSubscriptions' => '1',
            'smaily/subscribe/workflowId' => '101',
        ]);
        // "No automation workflow selected": 2.8.x sent no welcome there.
        $this->seed('websites', 2, ['smaily/subscribe/workflowId' => '']);
        $this->seed('websites', 3, [
            'smaily/subscribe/enableNewsletterSubscriptions' => '0',
            'smaily/subscribe/workflowId' => '',
        ]);
        $this->seed('websites', 4, ['smaily/general/enable' => '0', 'smaily/subscribe/workflowId' => '']);

        $this->migrate();

        foreach ([2, 3, 4] as $websiteId) {
            self::assertFalse($this->config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
        }
        self::assertTrue($this->config->isWelcomeEnabled(1));
        self::assertSame(
            [
                'Smaily for Magento 2.8.x sent no welcome email on these websites, because Enable Subscribers'
                . ' Collection was on there with no Autoresponder ID: Website 2. The welcome automation stays off'
                . ' there. To send one, open Marketing > Smaily Connect > Initial setup for each of these websites'
                . ' and, on the Automations step, tick Enabled for Welcome and pick a Smaily Workflow.',
            ],
            $this->noticeDescriptions(self::WELCOME_NOTICE_TITLE)
        );
    }

    public function testADefaultScopeOptInWithNoWorkflowNamesEveryWebsiteThatFollowsIt(): void
    {
        $this->env->resetState();
        $this->seed('default', 0, ['smaily/subscribe/enableNewsletterSubscriptions' => '1']);
        $this->seed('websites', 2, ['smaily/subscribe/workflowId' => '66']);
        StoreLocale::use('et_EE');

        $this->migrate();

        self::assertTrue($this->config->isWelcomeEnabled(2));
        foreach ([1, 3, 4] as $websiteId) {
            self::assertFalse($this->config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
        }
        self::assertSame(
            [
                'Smaily for Magento 2.8.x ei saatnud nendel veebisaitidel tervituskirja, sest seal oli Enable'
                . ' Subscribers Collection sees, kuid Autoresponder ID puudus: Website 1, Website 3, Website 4.'
                . ' Tervitusautomaatika jääb seal välja. Tervituskirja saatmiseks ava iga nimetatud veebisaidi'
                . ' jaoks Turundus > Smaily Connect > Algseadistus ning märgi sammul Automaatikad sündmuse'
                . ' Tervitus juures Lubatud ja vali Smaily töövoog.',
            ],
            $this->noticeDescriptions('Smaily Connecti uuendus: tervitusautomaatikal pole töövoogu')
        );
    }

    public function testNoWelcomeNoticeWhereEveryWebsiteHasAWorkflowOrTheOptInOff(): void
    {
        // setUp: every website has a workflow (website 4: Enable Module = No).
        self::assertSame([], $this->noticeDescriptions(self::WELCOME_NOTICE_TITLE));

        $this->env->resetState();
        $this->seed('default', 0, ['smaily/subscribe/enableNewsletterSubscriptions' => '0']);
        $this->seed('websites', 2, ['smaily/subscribe/workflowId' => '66']);

        $this->migrate();

        self::assertSame([], $this->noticeDescriptions(self::WELCOME_NOTICE_TITLE));
    }

    public function testTheWelcomeNoticeIsPostedOnceAndARerunDoesNotBringItBack(): void
    {
        $this->env->resetState();
        $this->seed('default', 0, ['smaily/subscribe/enableNewsletterSubscriptions' => '1']);

        $this->migrate();
        $this->migrate();

        self::assertCount(1, $this->noticeDescriptions(self::WELCOME_NOTICE_TITLE));
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

    /**
     * Run the migration and read its result through the real v3 getters.
     * The store manager names store view N "Store view N" and website N
     * "Website N", and lists websites 1 to 4.
     */
    private function migrate(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(function (int $storeId): StoreInterface {
            $store = $this->createMock(StoreInterface::class);
            $store->method('getName')->willReturn('Store view ' . $storeId);
            $store->method('getWebsiteId')->willReturn(self::STORES[$storeId][0]);

            return $store;
        });
        $storeManager->method('getWebsite')->willReturnCallback(function (int $websiteId): WebsiteInterface {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getName')->willReturn('Website ' . $websiteId);

            return $website;
        });
        $storeManager->method('getWebsites')->willReturnCallback(function (): array {
            $websites = [];
            foreach (array_unique(array_column(self::STORES, 0)) as $websiteId) {
                $website = $this->createMock(WebsiteInterface::class);
                $website->method('getId')->willReturn($websiteId);
                $website->method('getName')->willReturn('Website ' . $websiteId);
                $websites[] = $website;
            }

            return $websites;
        });

        /** @var ResourceConnection $resourceConnection */
        $resourceConnection = $this->objectManager->get(ResourceConnection::class);
        $this->objectManager->create(MigrateLegacyConfig::class, [
            'moduleDataSetup' => new DataSetup($resourceConnection),
            'storeManager' => $storeManager,
        ])->apply();

        $this->config = $this->objectManager->create(Config::class, ['scopeConfig' => $this->configScope()]);
    }

    /**
     * The descriptions of the admin notices posted with this title.
     *
     * @return string[]
     */
    private function noticeDescriptions(string $title): array
    {
        $descriptions = [];
        foreach ($this->env->getNotifier()->getNotifications() as $notification) {
            if ($notification['title'] === $title) {
                $descriptions[] = $notification['description'];
            }
        }

        return $descriptions;
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
