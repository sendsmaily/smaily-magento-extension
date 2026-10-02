<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncMode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * The wizard/Settings single-mode workflow selects (.smaily-w-workflow) share
 * the engine-automations preserve rule (PRO-1286): a saved workflow id missing
 * from the freshly loaded Smaily list is kept on an empty post instead of being
 * dropped, while a present id cleared to "-- Not Selected --" still clears.
 */
class WizardStepSaverTest extends TestCase
{
    /** @var WriterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $configWriter;

    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    /** @var SmailyClientProvider&\PHPUnit\Framework\MockObject\MockObject */
    private $clientProvider;

    /** @var SmailyClientFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $clientFactory;

    /** @var AccountResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $accountResolver;

    /** @var MappingSaver&\PHPUnit\Framework\MockObject\MockObject */
    private $mappingSaver;

    /** @var WebsiteContext&\PHPUnit\Framework\MockObject\MockObject */
    private $websiteContext;

    /** @var array<int, array{path: string, value: mixed, scope: string, scopeId: int}> */
    private array $saved = [];

    private WizardStepSaver $saver;

    protected function setUp(): void
    {
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->config = $this->createMock(Config::class);
        $this->clientProvider = $this->createMock(SmailyClientProvider::class);
        $this->clientFactory = $this->createMock(SmailyClientFactory::class);

        $normalizer = $this->createMock(SubdomainNormalizer::class);
        $normalizer->method('normalize')->willReturnArgument(0);
        $this->accountResolver = $this->createMock(AccountResolver::class);
        $this->mappingSaver = $this->createMock(MappingSaver::class);
        $this->websiteContext = $this->createMock(WebsiteContext::class);

        $this->saved = [];
        $this->configWriter->method('save')->willReturnCallback(
            function (
                string $path,
                $value = null,
                string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
                int $scopeId = 0
            ): WriterInterface {
                $this->saved[] = ['path' => $path, 'value' => $value, 'scope' => $scope, 'scopeId' => $scopeId];

                return $this->configWriter;
            }
        );

        $this->saver = new WizardStepSaver(
            $this->configWriter,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            $normalizer,
            $this->accountResolver,
            $this->config,
            $this->websiteContext,
            $this->createMock(StoreManagerInterface::class),
            $this->mappingSaver,
            $this->clientProvider,
            new ConfigRowNormalizer(),
            new CredentialCheck($this->clientFactory, $this->config)
        );
    }

    /**
     * @param array<int, array{id: int, title: string}> $workflows
     */
    private function withWorkflows(array $workflows): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->method('getAutomationWorkflows')->willReturn($workflows);
        $this->clientProvider->method('forStore')->willReturn($client);
    }

    private function savedValue(string $path): ?string
    {
        $value = null;
        foreach ($this->saved as $row) {
            if ($row['path'] === $path) {
                $value = (string)$row['value'];
            }
        }

        return $value;
    }

    private function wasSaved(string $path): bool
    {
        foreach ($this->saved as $row) {
            if ($row['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    private function wasSavedAtScope(string $path, string $scope, int $scopeId): bool
    {
        foreach ($this->saved as $row) {
            if ($row['path'] === $path && $row['scope'] === $scope && $row['scopeId'] === $scopeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{scope: string, scopeId: int}|null
     */
    private function savedScope(string $path): ?array
    {
        $scope = null;
        foreach ($this->saved as $row) {
            if ($row['path'] === $path) {
                $scope = ['scope' => $row['scope'], 'scopeId' => $row['scopeId']];
            }
        }

        return $scope;
    }

    public function testMissingSavedWorkflowIsPreservedOnEmptyPost(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(123);
        $this->withWorkflows([['id' => 456, 'title' => 'Other']]);

        $this->saver->save('automations', ['welcome_workflow' => '']);

        self::assertFalse(
            $this->wasSaved(Config::XML_PATH_WELCOME_WORKFLOW),
            'A saved id absent from the live list must not be overwritten by an empty post'
        );
    }

    public function testMissingSavedWorkflowIsPreservedWhenListFailsToLoad(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(123);
        $this->clientProvider->method('forStore')
            ->willThrowException(new SmailyClientException('no credentials'));

        $this->saver->save('automations', ['welcome_workflow' => '']);

        self::assertFalse($this->wasSaved(Config::XML_PATH_WELCOME_WORKFLOW));
    }

    public function testDeliberateClearOfPresentWorkflowIsHonored(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(456);
        $this->withWorkflows([['id' => 456, 'title' => 'Welcome']]);

        $this->saver->save('automations', ['welcome_workflow' => '']);

        self::assertSame('0', $this->savedValue(Config::XML_PATH_WELCOME_WORKFLOW));
    }

    public function testNewSelectionIsStored(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(0);
        $this->withWorkflows([['id' => 456, 'title' => 'Welcome']]);

        $this->saver->save('automations', ['welcome_workflow' => '456']);

        self::assertSame('456', $this->savedValue(Config::XML_PATH_WELCOME_WORKFLOW));
    }

    /**
     * PRO-1397: the Subscribers tab's orphan-field control (include_guests)
     * reuses this pre-existing saveFlag() wiring — confirm it persists.
     */
    public function testSubscriberOrphanFlagsAreSaved(): void
    {
        $this->saver->save('subscribers', ['include_guests' => true]);

        self::assertSame('1', $this->savedValue(Config::XML_PATH_INCLUDE_GUESTS));
    }

    /**
     * PRO-3577: the "force opt-in" setting is retired. A save that still
     * carries its key (an admin page cached from before the update) saves
     * the rest and never writes the retired path again.
     */
    public function testTheRetiredForceOptInKeyIsNeverSaved(): void
    {
        $this->saver->save('subscribers', ['include_guests' => true, 'automation_force_opt_in' => true]);

        self::assertSame('1', $this->savedValue(Config::XML_PATH_INCLUDE_GUESTS));
        self::assertFalse($this->wasSaved('smaily_connect/subscribers/automation_force_opt_in'));
    }

    /**
     * The wizard doesn't render these controls (Settings-only, per target
     * spec §2.3.B) — collect.subscribers() omits the keys there rather than
     * posting false, so an absent key must leave the stored value untouched.
     */
    public function testSubscriberOrphanFlagsAreUntouchedWhenKeyAbsent(): void
    {
        $this->saver->save('subscribers', ['sync_enabled' => true]);

        self::assertFalse($this->wasSaved(Config::XML_PATH_INCLUDE_GUESTS));
    }

    /**
     * PRO-1460: connection credentials and the multilingual mode write at
     * website scope (RFC_MULTI_WEBSITE.md §1) — no website chooser exists
     * yet, so this is the installation's default website (id 0 with a bare
     * StoreManager stub in this test harness).
     */
    public function testConnectCredentialsAreSavedAtWebsiteScope(): void
    {
        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'password' => 'secret',
            'multilingual_mode' => 'single',
        ]);

        $expectedScope = ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 0];
        self::assertSame('demo', $this->savedValue(Config::XML_PATH_SUBDOMAIN));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_SUBDOMAIN));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_USERNAME));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_PASSWORD));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_MULTILINGUAL_MODE));
    }

    /**
     * PRO-3560: saving the connection is a real check — Smaily is asked once
     * whether it accepts what was just saved (SmailyClient remembers the
     * answer for the status displays).
     */
    public function testSavingTheConnectionChecksTheSavedCredentials(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->expects(self::once())->method('validateCredentials');
        $this->clientFactory->expects(self::once())->method('create')
            ->with(['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'secret'])
            ->willReturn($client);

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'secret']);
    }

    public function testAKeptPasswordIsCheckedAsStored(): void
    {
        $this->websiteContext->method('getStoreId')->willReturn(1);
        $this->config->method('getPassword')->with(1)->willReturn('stored-secret');
        $this->clientFactory->expects(self::once())->method('create')
            ->with(['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'stored-secret'])
            ->willReturn($this->createMock(SmailyClient::class));

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => '******']);
    }

    public function testCredentialsSmailyRefusesAreStillSaved(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->method('validateCredentials')->willThrowException(new AuthenticationException('refused', 401));
        $this->clientFactory->method('create')->willReturn($client);

        $errors = $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'x']);

        self::assertSame([], $errors);
        self::assertSame('demo', $this->savedValue(Config::XML_PATH_SUBDOMAIN));
    }

    /**
     * PRO-3575: a subdomain that would change the request host is refused
     * with a message; nothing is saved and Smaily is not asked, so the stored
     * password is never sent with it.
     *
     * @dataProvider refusedConnectionProvider
     * @param array<string, mixed> $data
     */
    public function testASubdomainThatIsNotPlainIsRefusedAndNothingIsSaved(array $data): void
    {
        $this->accountResolver->method('storeIdsForAccountKey')->willReturn([5]);
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', $data);

        self::assertSame([[
            'field' => 'subdomain',
            'message' => 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
        ]], $errors);
        self::assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function refusedConnectionProvider(): array
    {
        return [
            'the account' => [['subdomain' => 'engine.example#', 'username' => 'api-user', 'password' => '']],
            'a per-language account' => [[
                'subdomain' => 'demo',
                'username' => 'api-user',
                'multilingual_mode' => 'a',
                'accounts' => [['language' => 'et', 'subdomain' => 'engine.example?', 'username' => 'et-user']],
            ]],
        ];
    }

    /**
     * The mode-A fallback language is informational only (its credentials,
     * not this hint, are the real per-website binding) and stays at default
     * scope, unchanged.
     */
    public function testFallbackLanguageStaysAtDefaultScope(): void
    {
        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'fallback_language' => 'en',
        ]);

        self::assertSame(
            ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0],
            $this->savedScope(Config::XML_PATH_FALLBACK_LANGUAGE)
        );
        self::assertSame('en', $this->savedValue(Config::XML_PATH_FALLBACK_LANGUAGE));
    }

    /**
     * PRO-1460: mode-A per-language accounts resolve their store views
     * through the target website (RFC_MULTI_WEBSITE.md §2) — the resolver
     * is asked for THIS save's website, not an installation-wide scan.
     */
    public function testModeAPerLanguageAccountsResolveStoreViewsWithinTheTargetWebsite(): void
    {
        $this->accountResolver->expects(self::once())
            ->method('storeIdsForAccountKey')
            ->with('et', 0)
            ->willReturn([5]);

        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'accounts' => [
                ['language' => 'et', 'subdomain' => 'demo-et', 'username' => 'et-user'],
            ],
        ]);

        self::assertTrue($this->wasSavedAtScope(Config::XML_PATH_USERNAME, ScopeInterface::SCOPE_STORES, 5));
    }

    public function testSubscriberFieldsAreSavedAtWebsiteScope(): void
    {
        $this->saver->save('subscribers', [
            'sync_enabled' => true,
            'sync_mode' => SyncMode::MODE_CONSENT,
            'sync_fields' => ['first_name'],
        ]);

        $expectedScope = ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 0];
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_SYNC_ENABLED));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_SYNC_MODE));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_SYNC_FIELDS));
    }

    public function testAutomationTogglesAndWorkflowsAreSavedAtWebsiteScope(): void
    {
        $this->config->method('getWelcomeWorkflow')->willReturn(0);
        $this->withWorkflows([['id' => 456, 'title' => 'Welcome']]);

        $this->saver->save('automations', [
            'welcome_enabled' => true,
            'welcome_workflow' => '456',
            'abandoned_cutoff' => 30,
        ]);

        $expectedScope = ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 0];
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_WELCOME_ENABLED));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_WELCOME_WORKFLOW));
        self::assertSame($expectedScope, $this->savedScope(Config::XML_PATH_ABANDONED_CUTOFF));
    }

    /**
     * PRO-1462 (RFC_MULTI_WEBSITE.md §6, Phase 3): the automation-mapping
     * table's admin save routes through the real target website instead of
     * the previously hardcoded website_id=0.
     */
    public function testAutomationMappingsAreSavedAtTheTargetWebsite(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(7);
        $this->accountResolver->method('languageStoreIds')->with(7)->willReturn([]);
        $this->withWorkflows([]);
        $this->mappingSaver->expects(self::once())
            ->method('save')
            ->with(self::anything(), 7, self::anything())
            ->willReturn([]);

        $this->saver->save('automations', [
            'mappings' => [
                ['trigger_type' => 'welcome', 'language' => 'default', 'workflow_id' => 5],
            ],
        ]);
    }

    /**
     * Engine tenant scoping is Phase 4 (RFC_MULTI_WEBSITE.md §3) and RSS is
     * outside §1's field list — both stay at default scope in this phase.
     */
    public function testIntelligenceAndRssStayAtDefaultScope(): void
    {
        $this->saver->save('intelligence', ['browse_tracking' => true]);
        $this->saver->save('rss', ['rss_enabled' => true]);

        $defaultScope = ['scope' => ScopeConfigInterface::SCOPE_TYPE_DEFAULT, 'scopeId' => 0];
        self::assertSame($defaultScope, $this->savedScope(EngineSettings::XML_PATH_BROWSE_TRACKING));
        self::assertSame($defaultScope, $this->savedScope(Config::XML_PATH_RSS_ENABLED));
    }

    /**
     * PRO-1461 (RFC_MULTI_WEBSITE.md §2, Phase 2): the setup-completed flag
     * is website-scoped so a second website's own wizard run is tracked
     * independently of the first.
     */
    public function testFinishSavesTheSetupCompletedFlagAtTheTargetWebsite(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(7);

        $this->saver->save('finish', []);

        self::assertSame(
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 7],
            $this->savedScope(WizardStepSaver::XML_PATH_SETUP_COMPLETED)
        );
    }
}
