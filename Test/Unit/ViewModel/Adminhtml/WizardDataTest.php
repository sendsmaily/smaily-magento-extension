<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;
use Smaily\Connect\ViewModel\Adminhtml\WizardData;

/**
 * PRO-1461 (docs/internal/RFC_MULTI_WEBSITE.md §2, Phase 2): every prefill path the
 * Settings selector / wizard chooser can switch between must actually read
 * the selected website's own scope — before this pass, getBootJson() called
 * most Config/Mode getters with no scope argument at all, so switching
 * websites would have silently kept showing the first website's values.
 */
class WizardDataTest extends TestCase
{
    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var Mode&\PHPUnit\Framework\MockObject\MockObject */
    private $mode;

    /** @var SetupGuard&\PHPUnit\Framework\MockObject\MockObject */
    private $setupGuard;

    /** @var WebsiteOverrides&\PHPUnit\Framework\MockObject\MockObject */
    private $overrides;

    /** @var AccountResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $accountResolver;

    /** @var WebsiteContext&\PHPUnit\Framework\MockObject\MockObject */
    private $websiteContext;

    /** @var VerifiedCredentials&\PHPUnit\Framework\MockObject\MockObject */
    private $verifiedCredentials;

    /** @var ContactAudience&\PHPUnit\Framework\MockObject\MockObject */
    private $contactAudience;

    /** @var ResolverInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $localeResolver;

    /** @var OrderOrigin&\PHPUnit\Framework\MockObject\MockObject */
    private $orderOrigin;

    private WizardData $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->mode = $this->createMock(Mode::class);
        $this->setupGuard = $this->createMock(SetupGuard::class);
        $this->overrides = $this->createMock(WebsiteOverrides::class);
        $this->accountResolver = $this->createMock(AccountResolver::class);
        $this->accountResolver->method('detectedLanguages')->willReturn([]);
        $this->websiteContext = $this->createMock(WebsiteContext::class);
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $this->websiteContext->method('getStoreId')->willReturn(5);
        $this->verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $this->localeResolver = $this->createMock(ResolverInterface::class);

        $this->contactAudience = $this->createMock(ContactAudience::class);

        $this->orderOrigin = $this->createMock(OrderOrigin::class);
        $this->viewModel = $this->viewModel($this->websiteContext);
    }

    private function viewModel(WebsiteContext $websiteContext): WizardData
    {
        return new WizardData(
            $this->config,
            $this->mode,
            $this->createMock(EngineSettings::class),
            $this->accountResolver,
            $this->contactAudience,
            new Json(),
            $this->createMock(MappingCollectionFactory::class),
            $websiteContext,
            $this->verifiedCredentials,
            $this->localeResolver,
            $this->createMock(ConsentSource::class),
            $this->orderOrigin,
            $this->setupGuard,
            $this->overrides
        );
    }

    /**
     * The initial setup on a store with more than one website (CON-179):
     * website 0, whose reads resolve the default scope.
     */
    private function storeWideViewModel(): WizardData
    {
        $context = $this->createMock(WebsiteContext::class);
        $context->method('isStoreWide')->willReturn(true);
        $context->method('hasMultipleWebsites')->willReturn(true);
        $context->method('getWebsiteId')->willReturn(WebsiteContext::STORE_WIDE);
        $context->method('getStoreId')->willReturn(0);
        $context->method('getWebsiteOptions')->willReturn([1 => 'Estonia', 2 => 'Latvia', 3 => 'Lithuania']);

        return $this->viewModel($context);
    }

    public function testGetBootJsonReadsConnectionFieldsAtTheSelectedWebsitesStoreScope(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->expects(self::atLeastOnce())->method('isConnected')->with(5)->willReturn(true);
        $this->config->expects(self::atLeastOnce())->method('getSubdomain')->with(5)->willReturn('demo');
        $this->config->expects(self::atLeastOnce())->method('getUsername')->with(5)->willReturn('user');
        $this->config->expects(self::atLeastOnce())->method('getPassword')->with(5)->willReturn('secret');

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertSame(5, $decoded['storeId']);
        self::assertSame('demo', $decoded['connection']['subdomain']);
        self::assertSame('user', $decoded['connection']['username']);
        self::assertTrue($decoded['connection']['hasPassword']);
    }

    /**
     * PRO-3718: the form compares a password-less account with the account
     * saved for the website, as the save does — not with the store view's
     * account the fields show.
     */
    public function testGetBootJsonCarriesTheAccountSavedForTheWebsite(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('getSubdomain')->willReturn('et-shop');
        $this->config->method('getUsername')->willReturn('et-user');
        $this->config->method('getWebsiteSubdomain')->with(2)->willReturn('en-shop');
        $this->config->method('getWebsiteUsername')->with(2)->willReturn('en-user');

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertSame(['subdomain' => 'en-shop', 'username' => 'en-user'], $decoded['websiteAccount']);
        self::assertSame('et-shop', $decoded['connection']['subdomain']);
    }

    /**
     * PRO-4062: the transactional-email account is drawn for the selected
     * website without its password — only whether one is saved.
     */
    public function testTheTransactionalAccountNeverCarriesItsPassword(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('getTransactionalSubdomain')->willReturnMap([[2, 'demo-tx'], [null, 'shop-tx']]);
        $this->config->method('getTransactionalUsername')->willReturnMap([[2, 'tx'], [null, 'api']]);
        $this->config->method('getTransactionalPassword')->willReturnMap([[2, 'tx-secret'], [null, 'secret']]);
        $this->config->method('hasTransactionalWebsiteAccount')->with(2)->willReturn(true);
        $this->verifiedCredentials->method('isTransactionalVerified')->with(2)->willReturn(true);
        $this->config->method('isTransactionalEnabled')->with(2)->willReturn(true);

        $account = $this->viewModel->getTransactionalAccount();

        self::assertSame(
            [
                'enabled' => true,
                'subdomain' => 'demo-tx',
                'username' => 'tx',
                'hasPassword' => true,
                'websiteAccount' => true,
                'verified' => true,
                'shared' => ['subdomain' => 'shop-tx', 'username' => 'api', 'hasPassword' => true],
            ],
            $account
        );
        self::assertStringNotContainsString('secret', (string)json_encode($account));
        self::assertStringNotContainsString('secret', $this->viewModel->getBootJson());
    }

    /**
     * PRO-3719: each website shows its own default fallback language.
    public function testTheFallbackLanguageIsTheSelectedWebsites(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('getFallbackLanguage')->willReturnMap([[null, 'et'], [2, 'en']]);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertSame('en', $decoded['multilingual']['fallbackLanguage']);
        self::assertSame('en', $this->viewModel->getFallbackLanguage());
    }

    /**
     * PRO-3560: filled-in credentials are not "Connected" — the Connection
     * status follows whether Smaily accepted them at the last real check.
     */
    public function testSavedCredentialsSmailyHasNotAcceptedAreNotShownAsConnected(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('isConnected')->willReturn(true);
        $this->verifiedCredentials->expects(self::once())->method('isWebsiteVerified')->with(2)->willReturn(false);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['connected']);
        self::assertFalse($decoded['verified']);
    }

    public function testSavedCredentialsSmailyAcceptedAreShownAsConnected(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->verifiedCredentials->method('isWebsiteVerified')->with(2)->willReturn(true);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['verified']);
        self::assertFalse($decoded['planBlocked']);
    }

    /**
     * PRO-3570: the Connection status is rendered into the page, so no
     * wrong state shows before the script runs. PRO-3719: it describes the
     * account saved for the website — the account a connection save checks
     * — not the account the website's default store view holds.
     */
    public function testTheConnectionStatusDescribesTheAccountSavedForTheWebsite(): void
    {
        $this->verifiedCredentials->method('isVerified')->with(5)->willReturn(false);
        $this->verifiedCredentials->expects(self::once())->method('isWebsiteVerified')->with(2)->willReturn(true);
        $this->config->method('getSubdomain')->with(5)->willReturn('et-shop');
        $this->config->method('getUsername')->with(5)->willReturn('et-user');
        $this->config->method('getWebsiteSubdomain')->with(2)->willReturn('demo');
        $this->config->method('getWebsiteUsername')->with(2)->willReturn('api-user');

        self::assertTrue($this->viewModel->isSmailyVerified());
        self::assertSame('demo', $this->viewModel->getSavedSubdomain());
        self::assertSame('api-user', $this->viewModel->getSavedUsername());
    }

    /**
     * PRO-3660: the Storefront URL field shows the selected website's value.
     */
    public function testTheStorefrontUrlIsReadAtTheSelectedWebsitesStoreScope(): void
    {
        $this->config->method('getStorefrontUrl')->with(5)->willReturn('https://shop.example.com');

        self::assertSame('https://shop.example.com', $this->viewModel->getSavedStorefrontUrl());
    }

    public function testTheStorefrontUrlFieldOpensOnAnApiOnlyStore(): void
    {
        $this->orderOrigin->method('isApiOnly')->willReturn(true);

        self::assertTrue($this->viewModel->isApiOnlyStore());
    }

    /**
     * @dataProvider storefrontDisclosureProvider
     */
    public function testTheStorefrontDisclosureOpensWithASavedAddressOrOnAnApiOnlyStore(
        string $saved,
        bool $apiOnly,
        bool $expected
    ): void {
        $this->config->method('getStorefrontUrl')->with(5)->willReturn($saved);
        $this->orderOrigin->method('isApiOnly')->willReturn($apiOnly);

        self::assertSame($expected, $this->viewModel->isStorefrontDisclosureOpen());
    }

    /**
     * @return array<string, array{string, bool, bool}>
     */
    public static function storefrontDisclosureProvider(): array
    {
        return [
            'nothing saved, checkout orders' => ['', false, false],
            'address saved' => ['https://shop.example.com', false, true],
            'API-only store' => ['', true, true],
            'both' => ['https://shop.example.com', true, true],
        ];
    }

    /**
     * PRO-3579: when Smaily's last answer was that the package has no API
     * access, the Connection status says so instead of blaming the
     * credentials.
     */
    public function testAPackageWithoutApiAccessIsShownAsTheReason(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->verifiedCredentials->method('isWebsiteVerified')->with(2)->willReturn(false);
        $this->verifiedCredentials->method('isWebsitePlanBlocked')->with(2)->willReturn(true);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertFalse($decoded['verified']);
        self::assertTrue($decoded['planBlocked']);
    }

    public function testGetBootJsonReadsSubscriberAndAutomationFieldsAtTheSelectedWebsiteScope(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->expects(self::atLeastOnce())->method('isSyncEnabled')->with(2)->willReturn(true);
        $this->mode->expects(self::atLeastOnce())->method('mode')->with(2)->willReturn('consent');
        $this->config->expects(self::atLeastOnce())->method('isWelcomeEnabled')->with(2)->willReturn(true);
        $this->config->expects(self::atLeastOnce())->method('getWelcomeWorkflow')->with(2)->willReturn(10);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['subscribers']['syncEnabled']);
        self::assertSame('consent', $decoded['subscribers']['syncMode']);
        self::assertTrue($decoded['automations']['welcomeEnabled']);
        self::assertSame('10', $decoded['automations']['welcomeWorkflow']);
    }

    public function testIsSetupCompletedIsTheStoresMark(): void
    {
        $this->setupGuard->expects(self::once())->method('isSetupCompleted')->willReturn(true);

        self::assertTrue($this->viewModel->isSetupCompleted());
    }

    /**
     * PRO-2456: the initial setup draws the step it opens on server-side, so
     * the page does not jump when the script runs.
     */
    public function testAFreshSetupOpensOnConnectWithOnlyThatStepReached(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('isConnected')->willReturn(false);

        self::assertSame(1, $this->viewModel->getStartStep());
        self::assertSame(1, $this->viewModel->getReachedStep());
    }

    /**
     * CON-179: Smaily login details saved already (carried over from 2.8.x)
     * do not skip the Connect step: an unfinished setup opens on it, as in
     * the WooCommerce plugin.
     */
    public function testSavedCredentialsStillOpenAnUnfinishedSetupOnConnect(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('isConnected')->willReturn(true);

        self::assertSame(1, $this->viewModel->getStartStep());
        self::assertSame(1, $this->viewModel->getReachedStep());
    }

    public function testAFinishedSetupOpensOnConnectWithEveryStepReached(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(true);
        $this->config->method('isConnected')->willReturn(true);

        self::assertSame(1, $this->viewModel->getStartStep());
        self::assertSame(5, $this->viewModel->getReachedStep());
    }

    public function testSelectorHelpersDelegateToWebsiteContext(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->websiteContext->method('getWebsiteOptions')->willReturn([1 => 'Main', 2 => 'Second']);

        self::assertSame(2, $this->viewModel->getSelectedWebsiteId());
        self::assertTrue($this->viewModel->hasMultipleWebsites());
        self::assertSame([1 => 'Main', 2 => 'Second'], $this->viewModel->getWebsiteOptions());
        self::assertSame(['Main', 'Second'], $this->viewModel->getWebsiteNames());
        self::assertSame(['_query' => ['website' => 2]], $this->viewModel->getWebsiteQuery());
    }

    /**
     * CON-179: the initial setup's calls carry website 0, the whole store.
     */
    public function testTheInitialSetupsCallsTargetTheWholeStore(): void
    {
        self::assertSame(['_query' => ['website' => 0]], $this->storeWideViewModel()->getWebsiteQuery());
    }

    /**
     * CON-179 (criterion 5): the initial setup lists each website that holds
     * its own values of a step's settings, with those values in the
     * admin's words — never a password.
     */
    public function testTheInitialSetupListsTheWebsitesWithTheirOwnValues(): void
    {
        $this->overrides->method('ownValues')->with(WebsiteOverrides::CONNECTION)->willReturn([
            3 => [
                Config::XML_PATH_SUBDOMAIN => 'lt-shop',
                Config::XML_PATH_PASSWORD => 'encrypted',
                Config::XML_PATH_MULTILINGUAL_MODE => 'b',
            ],
        ]);

        $list = $this->storeWideViewModel()->getWebsiteOverrides(WebsiteOverrides::CONNECTION);

        self::assertSame([[
            'websiteId' => 3,
            'name' => 'Lithuania',
            'values' => [
                ['Subdomain', 'lt-shop'],
                ['API password', '••••••••'],
                ['', 'One account, per-language workflows'],
            ],
            'required' => false,
        ]], $list);
    }

    /**
     * CON-190: the initial setup offers no "Use this step's values" for the
     * automations of a website with its own Smaily account.
     */
    public function testTheInitialSetupKeepsTheAutomationsOfAWebsiteWithItsOwnAccount(): void
    {
        $this->overrides->method('ownValues')->with(WebsiteOverrides::AUTOMATIONS)->willReturn([
            2 => [Config::XML_PATH_WELCOME_WORKFLOW => '7'],
            3 => [Config::XML_PATH_WELCOME_WORKFLOW => '8'],
        ]);
        $this->overrides->method('usesOtherAccount')->willReturnCallback(static fn (int $id): bool => $id === 2);

        $list = $this->storeWideViewModel()->getWebsiteOverrides(WebsiteOverrides::AUTOMATIONS);

        self::assertSame([2 => true, 3 => false], array_column($list, 'required', 'websiteId'));
    }

    /**
     * CON-190: a website with its own Smaily account keeps its own
     * automations, as a website in mode A does: their switch is ticked and
     * fixed, and Settings asks for its workflows while it holds none — the
     * store-wide workflow it would inherit is not shown as its own.
     */
    public function testAWebsiteWithItsOwnAccountMustKeepItsOwnAutomations(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->config->method('getMultilingualMode')->willReturn(MultilingualMode::MODE_SINGLE);
        $this->config->method('getWelcomeWorkflow')->willReturn(555);
        $this->overrides->method('usesOtherAccount')->with(2)->willReturn(true);
        $this->overrides->method('ownValues')->willReturn([2 => [Config::XML_PATH_WELCOME_ENABLED => '1']]);
        $this->overrides->method('ownValue')->willReturn(null);

        self::assertTrue($this->viewModel->isOwnRequired(WebsiteOverrides::AUTOMATIONS));
        self::assertTrue($this->viewModel->hasOwnValues(WebsiteOverrides::AUTOMATIONS));
        self::assertTrue($this->viewModel->needsOwnWorkflows());
        self::assertSame('', json_decode($this->viewModel->getBootJson(), true)['automations']['welcomeWorkflow']);
    }

    public function testAWebsiteWithItsOwnAccountAndItsOwnWorkflowIsNotAskedForOne(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->overrides->method('usesOtherAccount')->willReturn(true);
        $this->overrides->method('ownValues')->willReturn([2 => [Config::XML_PATH_FIRST_ORDER_WORKFLOW => '42']]);
        $this->overrides->method('ownValue')->willReturnCallback(
            static fn (string $section, int $websiteId, string $path): ?string
                => $path === Config::XML_PATH_FIRST_ORDER_WORKFLOW ? '42' : null
        );

        self::assertFalse($this->viewModel->needsOwnWorkflows());
        self::assertSame('42', json_decode($this->viewModel->getBootJson(), true)['automations']['firstOrderWorkflow']);
    }

    /**
     * A website on the store-wide Smaily account (and every single-website
     * install, CON-190) shows the workflows it reads, asks for none, and its
     * automations switch stays free.
     */
    public function testAWebsiteOnTheStoreWideAccountKeepsItsInheritedWorkflows(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->config->method('getWelcomeWorkflow')->willReturn(555);
        $this->overrides->method('usesOtherAccount')->willReturn(false);

        self::assertFalse($this->viewModel->isOwnRequired(WebsiteOverrides::AUTOMATIONS));
        self::assertFalse($this->viewModel->needsOwnWorkflows());
        self::assertSame('555', json_decode($this->viewModel->getBootJson(), true)['automations']['welcomeWorkflow']);
    }

    /**
     * Per-language Smaily accounts are always a website's own: on Settings,
     * a website in mode A keeps its own automations, so their switch is
     * ticked and fixed.
     */
    public function testAWebsiteWithPerLanguageAccountsMustKeepItsOwnAutomations(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->config->method('getMultilingualMode')->with(2)->willReturn(MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS);
        $this->overrides->method('hasOwnValues')->willReturn(false);

        self::assertTrue($this->viewModel->isOwnRequired(WebsiteOverrides::AUTOMATIONS));
        self::assertTrue($this->viewModel->hasOwnValues(WebsiteOverrides::AUTOMATIONS));
        self::assertFalse($this->viewModel->isOwnRequired(WebsiteOverrides::CONNECTION));
    }

    public function testSettingsListsNoWebsitesButKnowsTheSelectedWebsitesOwnValues(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->overrides->method('hasOwnValues')->with(WebsiteOverrides::SUBSCRIBERS, 2)->willReturn(true);

        self::assertSame([], $this->viewModel->getWebsiteOverrides(WebsiteOverrides::SUBSCRIBERS));
        self::assertTrue($this->viewModel->hasOwnValues(WebsiteOverrides::SUBSCRIBERS));
    }

    /**
     * CON-179: the initial setup covers every website's languages and
     * contacts; per-language accounts are a website's own, so it shows none.
     */
    public function testTheInitialSetupCoversEveryWebsite(): void
    {
        $this->accountResolver->method('detectedLanguagesOfAllWebsites')->willReturn(['et', 'lv']);
        $this->config->method('getMultilingualMode')->willReturn(MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS);
        $this->accountResolver->expects(self::never())->method('languageStoreIds');
        $this->contactAudience->method('count')->willReturnCallback(
            static fn (int $websiteId, string $mode): int => $mode === SyncMode::MODE_CONSENT ? $websiteId * 10 : 0
        );
        $viewModel = $this->storeWideViewModel();

        self::assertSame(['et', 'lv'], $viewModel->getDetectedLanguages());
        self::assertSame(MultilingualMode::MODE_SINGLE, $viewModel->getMultilingualMode());
        self::assertSame([], $viewModel->getMultilingualAccounts());
        self::assertSame(60, $viewModel->getContactImportCounts()[SyncMode::MODE_CONSENT]);
    }

    public function testGetSelectedSyncFieldsReadsAtTheSelectedWebsiteScope(): void
    {
        $this->config->expects(self::once())->method('getSyncFields')->with(2)->willReturn(['first_name']);

        self::assertSame(['first_name'], $this->viewModel->getSelectedSyncFields());
    }

    /**
     * PRO-1764: the wizard's import control is server-rendered from this
     * answer, so it must be the selected website's own.
     */
    public function testIsSyncEnabledReadsAtTheSelectedWebsiteScope(): void
    {
        $this->config->expects(self::once())->method('isSyncEnabled')->with(2)->willReturn(false);

        self::assertFalse($this->viewModel->isSyncEnabled());
    }

    /**
     * PRO-4036: the Contacts step says that contacts start syncing with its
     * save only for a website whose contacts do not sync yet.
     */
    public function testIsContactSyncActiveReadsAtTheSelectedWebsiteScope(): void
    {
        $this->config->expects(self::once())->method('isContactSyncActive')->with(2)->willReturn(true);

        self::assertTrue($this->viewModel->isContactSyncActive());
    }

    /**
     * PRO-4037: the contacts import card follows the selected website's
     * answer, not the switch alone.
     */
    public function testTheBootDataCarriesWhetherTheSelectedWebsitesContactsSync(): void
    {
        $this->setupGuard->method('isSetupCompleted')->willReturn(false);
        $this->config->method('isSyncEnabled')->with(2)->willReturn(true);
        $this->config->method('isContactSyncActive')->with(2)->willReturn(false);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['subscribers']['syncEnabled']);
        self::assertFalse($decoded['subscribers']['syncActive']);
    }

    /**
     * PRO-3582: the import estimate counts the audience the import sends,
     * for every mode the panel offers, on the selected website.
     */
    public function testContactImportCountsFollowEachModesAudienceOnTheSelectedWebsite(): void
    {
        $this->contactAudience->method('count')->willReturnMap([
            [2, SyncMode::MODE_CONSENT, 5],
            [2, SyncMode::MODE_LEGITIMATE_INTEREST, 7],
            [2, SyncMode::MODE_CHECKOUT_OPTIN, 0],
        ]);

        self::assertSame(
            [
                SyncMode::MODE_CONSENT => 5,
                SyncMode::MODE_LEGITIMATE_INTEREST => 7,
                SyncMode::MODE_CHECKOUT_OPTIN => 0,
            ],
            $this->viewModel->getContactImportCounts()
        );
    }

    public function testGetSyncModeReadsAtTheSelectedWebsiteScope(): void
    {
        $this->mode->expects(self::once())->method('mode')->with(2)->willReturn(SyncMode::MODE_LEGITIMATE_INTEREST);

        self::assertSame(SyncMode::MODE_LEGITIMATE_INTEREST, $this->viewModel->getSyncMode());
    }

    /**
     * PRO-3566: each per-language account block shows its language by name
     * and its own connection status — whether Smaily accepted the
     * credentials saved for that language's store view at the last check.
     */
    public function testEachPerLanguageAccountCarriesItsLanguageNameAndItsOwnConnectionStatus(): void
    {
        $this->accountResolver->method('languageStoreIds')->with(2)->willReturn(['en' => 5, 'et' => 7]);
        $this->verifiedCredentials->method('isVerified')->willReturnMap([[5, true], [7, false]]);
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        $accounts = $this->viewModel->getMultilingualAccounts();

        self::assertSame(['English', 'Estonian'], array_column($accounts, 'languageName'));
        self::assertSame([true, false], array_column($accounts, 'verified'));
        self::assertSame([5, 7], array_column($accounts, 'storeId'));
    }

    public function testTheLanguageNameFollowsTheAdminsInterfaceLocale(): void
    {
        $this->accountResolver->method('languageStoreIds')->willReturn(['en' => 5]);
        $this->localeResolver->method('getLocale')->willReturn('et_EE');

        self::assertSame('inglise', $this->viewModel->getMultilingualAccounts()[0]['languageName']);
    }

    public function testALanguageWithoutAKnownNameFallsBackToItsCode(): void
    {
        $this->accountResolver->method('languageStoreIds')->willReturn(['zz' => 5]);
        $this->localeResolver->method('getLocale')->willReturn('en_US');

        self::assertSame('ZZ', $this->viewModel->getMultilingualAccounts()[0]['languageName']);
    }
}
