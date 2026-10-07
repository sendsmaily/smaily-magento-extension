<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
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

    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

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
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->accountResolver = $this->createMock(AccountResolver::class);
        $this->accountResolver->method('detectedLanguages')->willReturn([]);
        $this->websiteContext = $this->createMock(WebsiteContext::class);
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $this->websiteContext->method('getStoreId')->willReturn(5);
        $this->verifiedCredentials = $this->createMock(VerifiedCredentials::class);
        $this->localeResolver = $this->createMock(ResolverInterface::class);

        $this->contactAudience = $this->createMock(ContactAudience::class);

        $this->orderOrigin = $this->createMock(OrderOrigin::class);
        $this->viewModel = new WizardData(
            $this->config,
            $this->mode,
            $this->createMock(EngineSettings::class),
            $this->scopeConfig,
            $this->accountResolver,
            $this->contactAudience,
            new Json(),
            $this->createMock(MappingCollectionFactory::class),
            $this->websiteContext,
            $this->verifiedCredentials,
            $this->localeResolver,
            $this->createMock(ConsentSource::class),
            $this->orderOrigin
        );
    }

    public function testGetBootJsonReadsConnectionFieldsAtTheSelectedWebsitesStoreScope(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
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
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->config->method('getSubdomain')->willReturn('et-shop');
        $this->config->method('getUsername')->willReturn('et-user');
        $this->config->method('getWebsiteSubdomain')->with(2)->willReturn('en-shop');
        $this->config->method('getWebsiteUsername')->with(2)->willReturn('en-user');

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertSame(['subdomain' => 'en-shop', 'username' => 'en-user'], $decoded['websiteAccount']);
        self::assertSame('et-shop', $decoded['connection']['subdomain']);
    }

    /**
     * PRO-3719: each website shows its own default fallback language.
     */
    public function testTheFallbackLanguageIsTheSelectedWebsites(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
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
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->config->method('isConnected')->willReturn(true);
        $this->verifiedCredentials->expects(self::once())->method('isWebsiteVerified')->with(2)->willReturn(false);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['connected']);
        self::assertFalse($decoded['verified']);
    }

    public function testSavedCredentialsSmailyAcceptedAreShownAsConnected(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
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
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->verifiedCredentials->method('isWebsiteVerified')->with(2)->willReturn(false);
        $this->verifiedCredentials->method('isWebsitePlanBlocked')->with(2)->willReturn(true);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertFalse($decoded['verified']);
        self::assertTrue($decoded['planBlocked']);
    }

    public function testGetBootJsonReadsSubscriberAndAutomationFieldsAtTheSelectedWebsiteScope(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
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

    public function testIsSetupCompletedReadsAtTheSelectedWebsitesScope(): void
    {
        $this->scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(WizardStepSaver::XML_PATH_SETUP_COMPLETED, ScopeInterface::SCOPE_WEBSITE, 2)
            ->willReturn(true);

        self::assertTrue($this->viewModel->isSetupCompleted());
    }

    /**
     * PRO-2456: the initial setup draws the step it opens on server-side, so
     * the page does not jump when the script runs.
     */
    public function testAFreshSetupOpensOnConnectWithOnlyThatStepReached(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->config->method('isConnected')->willReturn(false);

        self::assertSame(1, $this->viewModel->getStartStep());
        self::assertSame(1, $this->viewModel->getReachedStep());
    }

    public function testFilledInCredentialsOpenAnUnfinishedSetupOnContacts(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->config->expects(self::atLeastOnce())->method('isConnected')->with(5)->willReturn(true);

        self::assertSame(2, $this->viewModel->getStartStep());
        self::assertSame(2, $this->viewModel->getReachedStep());
    }

    public function testAFinishedSetupOpensOnConnectWithEveryStepReached(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->config->method('isConnected')->willReturn(true);

        self::assertSame(1, $this->viewModel->getStartStep());
        self::assertSame(5, $this->viewModel->getReachedStep());
    }

    public function testSelectorHelpersDelegateToWebsiteContext(): void
    {
        $this->websiteContext->method('hasMultipleWebsites')->willReturn(true);
        $this->websiteContext->method('isExplicit')->willReturn(false);
        $this->websiteContext->method('getWebsiteOptions')->willReturn([1 => 'Main', 2 => 'Second']);

        self::assertSame(2, $this->viewModel->getSelectedWebsiteId());
        self::assertTrue($this->viewModel->hasMultipleWebsites());
        self::assertFalse($this->viewModel->hasSelectedWebsite());
        self::assertSame([1 => 'Main', 2 => 'Second'], $this->viewModel->getWebsiteOptions());
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
