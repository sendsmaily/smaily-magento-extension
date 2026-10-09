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
use Smaily\Connect\Model\Adminhtml\SetupNotice;
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

    /** @var SetupNotice&\PHPUnit\Framework\MockObject\MockObject */
    private $setupNotice;

    /** @var array<int, array{path: string, value: mixed, scope: string, scopeId: int}> */
    private array $saved = [];

    private WizardStepSaver $saver;

    protected function setUp(): void
    {
        $this->configWriter = $this->createMock(WriterInterface::class);
        $this->config = $this->createMock(Config::class);
        // The account the connection tests post is the one already saved,
        // so an empty password keeps it (PRO-3690).
        $this->config->method('getSubdomain')->willReturn('demo');
        $this->config->method('getUsername')->willReturn('api-user');
        $this->config->method('getWebsiteSubdomain')->willReturn('demo');
        $this->config->method('getWebsiteUsername')->willReturn('api-user');
        $this->clientProvider = $this->createMock(SmailyClientProvider::class);
        $this->clientFactory = $this->createMock(SmailyClientFactory::class);

        $normalizer = $this->createMock(SubdomainNormalizer::class);
        $normalizer->method('normalize')->willReturnArgument(0);
        $this->accountResolver = $this->createMock(AccountResolver::class);
        $this->mappingSaver = $this->createMock(MappingSaver::class);
        $this->websiteContext = $this->createMock(WebsiteContext::class);
        $this->setupNotice = $this->createMock(SetupNotice::class);

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
            new CredentialCheck($this->clientFactory, $this->config),
            $this->setupNotice
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
     * PRO-4010: the initial setup's Contacts step renders no contact-sync
     * switch for a website whose sync is on, so its save carries no
     * sync_enabled; the stored answer stays as it is. A website whose sync
     * is off gets the switch, and its answer is saved as posted.
     */
    public function testContactSyncIsUntouchedWhenTheStepPostsNoAnswer(): void
    {
        $this->saver->save('subscribers', ['sync_mode' => SyncMode::MODE_CONSENT, 'sync_fields' => []]);

        self::assertFalse($this->wasSaved(Config::XML_PATH_SYNC_ENABLED));
    }

    public function testContactSyncIsSavedOffWhenTheStepPostsOff(): void
    {
        $this->saver->save('subscribers', ['sync_enabled' => false, 'sync_mode' => SyncMode::MODE_CONSENT]);

        self::assertSame('0', $this->savedValue(Config::XML_PATH_SYNC_ENABLED));
    }

    /**
     * PRO-1460: connection credentials and the multilingual mode write at
     * website scope (docs/internal/RFC_MULTI_WEBSITE.md §1) — no website chooser exists
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

    /**
     * PRO-3717: the password saved for the website — the scope the
     * subdomain and username are saved at — not its default store view's.
     */
    public function testAKeptPasswordIsCheckedAsStored(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(3);
        $this->websiteContext->method('getStoreId')->willReturn(1);
        $this->config->method('getPassword')->willReturn('store-view-secret');
        $this->config->method('getWebsitePassword')->with(3)->willReturn('stored-secret');
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
     * PRO-3570: the connection status shows the answer of the check a save
     * made, without a reload — the saver tells whether Smaily accepted the
     * credentials it just saved.
     */
    public function testAConnectionSaveTellsWhetherSmailyAcceptedTheCredentials(): void
    {
        $this->clientFactory->method('create')->willReturn($this->createMock(SmailyClient::class));

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'secret']);

        self::assertTrue($this->saver->isConnectionAccepted());
    }

    public function testAConnectionSaveSmailyRefusesIsNotAccepted(): void
    {
        $client = $this->createMock(SmailyClient::class);
        $client->method('validateCredentials')->willThrowException(new AuthenticationException('refused', 401));
        $this->clientFactory->method('create')->willReturn($client);

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'x']);

        self::assertFalse($this->saver->isConnectionAccepted());
    }

    public function testAConnectionSaveWithoutAPasswordToCheckIsNotAccepted(): void
    {
        $this->config->method('getWebsitePassword')->willReturn('');
        $this->clientFactory->expects(self::never())->method('create');

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => '']);

        self::assertFalse($this->saver->isConnectionAccepted());
    }

    /**
     * PRO-3690: an empty or masked password keeps the saved one only for the
     * saved account — a changed subdomain or username needs the password,
     * refused on its field before anything is saved or checked.
     *
     * @dataProvider changedAccountProvider
     */
    public function testAChangedAccountWithoutAPasswordIsRefusedAndNothingIsSaved(
        string $subdomain,
        string $username,
        string $password
    ): void {
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', [
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
            'multilingual_mode' => 'single',
        ]);

        self::assertSame([[
            'field' => 'password',
            'message' => 'The subdomain or username changed — enter the password of this account.',
        ]], $errors);
        self::assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function changedAccountProvider(): array
    {
        return [
            'another subdomain' => ['other', 'api-user', ''],
            'another username' => ['demo', 'other-user', ''],
            'masked password' => ['other', 'api-user', '******'],
        ];
    }

    /**
     * @dataProvider keptAccountProvider
     */
    public function testAnAccountThatKeepsItsPasswordIsSaved(
        string $subdomain,
        string $username,
        string $password
    ): void {
        $errors = $this->saver->save('connect', [
            'subdomain' => $subdomain,
            'username' => $username,
            'password' => $password,
        ]);

        self::assertSame([], $errors);
        self::assertSame($subdomain, $this->savedValue(Config::XML_PATH_SUBDOMAIN));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function keptAccountProvider(): array
    {
        return [
            'the saved account' => ['demo', 'api-user', ''],
            'the saved subdomain in capitals' => ['DEMO', 'api-user', ''],
            'another account with its password' => ['other', 'other-user', 'secret'],
        ];
    }

    /**
     * PRO-3690: a per-language block drawn with one account (its store
     * view's) that names another needs that account's password; the field
     * error names the block's password field.
     */
    public function testAPerLanguageBlockWithAnotherAccountAndNoPasswordIsRefused(): void
    {
        $this->accountResolver->method('storeLanguages')->with(0)->willReturn([3 => 'fi']);
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'multilingual_mode' => 'a',
            'accounts' => [
                ['language' => 'fi', 'subdomain' => 'fi-shop', 'username' => 'fi-user', 'password' => ''],
            ],
        ]);

        self::assertSame([[
            'field' => 'accounts.fi.password',
            'message' => 'The subdomain or username of the FI account changed — enter its password.',
        ]], $errors);
        self::assertSame([], $this->saved);
    }

    /**
     * PRO-3718: a new default fallback account without its password is also
     * a website account without its password — its block shows one error,
     * the block's own.
     */
    public function testANewFallbackBlockWithoutAPasswordShowsOneError(): void
    {
        $this->accountResolver->method('storeLanguages')->with(0)->willReturn([3 => 'fi']);
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', [
            'subdomain' => 'fi-shop',
            'username' => 'fi-user',
            'password' => '',
            'multilingual_mode' => 'a',
            'fallback_language' => 'fi',
            'accounts' => [
                ['language' => 'fi', 'subdomain' => 'fi-shop', 'username' => 'fi-user', 'password' => ''],
            ],
        ]);

        self::assertSame([[
            'field' => 'accounts.fi.password',
            'message' => 'The subdomain or username of the FI account changed — enter its password.',
        ]], $errors);
        self::assertSame([], $this->saved);
    }

    public function testAPerLanguageBlockWithItsOwnAccountKeepsItsPassword(): void
    {
        $this->accountResolver->method('storeLanguages')->willReturn([3 => 'fi']);

        $errors = $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'multilingual_mode' => 'a',
            'accounts' => [
                ['language' => 'fi', 'subdomain' => 'Demo', 'username' => 'api-user', 'password' => ''],
            ],
        ]);

        self::assertSame([], $errors);
        self::assertTrue($this->wasSavedAtScope(Config::XML_PATH_USERNAME, ScopeInterface::SCOPE_STORES, 3));
        self::assertFalse($this->wasSavedAtScope(Config::XML_PATH_PASSWORD, ScopeInterface::SCOPE_STORES, 3));
    }

    /**
     * PRO-3575: a subdomain that would change the request host is refused
     * with a message; nothing is saved and Smaily is not asked, so the stored
     * password is never sent with it.
     *
     * PRO-3562: the error names the field that caused it, so the form marks
     * that field — a per-language account's own subdomain field included.
     *
     * @dataProvider refusedConnectionProvider
     * @param array<string, mixed> $data
     */
    public function testASubdomainThatIsNotPlainIsRefusedAndNothingIsSaved(array $data, string $field): void
    {
        $this->accountResolver->method('storeIdsForAccountKey')->willReturn([5]);
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', $data);

        self::assertSame([[
            'field' => $field,
            'message' => 'The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.',
        ]], $errors);
        self::assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function refusedConnectionProvider(): array
    {
        return [
            'the account' => [
                ['subdomain' => 'engine.example#', 'username' => 'api-user', 'password' => ''],
                'subdomain',
            ],
            'a per-language account' => [[
                'subdomain' => 'demo',
                'username' => 'api-user',
                'multilingual_mode' => 'a',
                'accounts' => [['language' => 'et', 'subdomain' => 'engine.example?', 'username' => 'et-user']],
            ], 'accounts.et.subdomain'],
            'the fallback account, posted twice' => [[
                'subdomain' => 'engine.example?',
                'username' => 'et-user',
                'multilingual_mode' => 'a',
                'accounts' => [['language' => 'et', 'subdomain' => 'engine.example?', 'username' => 'et-user']],
            ], 'accounts.et.subdomain'],
        ];
    }

    /**
     * PRO-3562: an empty required field is named, each one on its own.
     *
     * @dataProvider emptyFieldProvider
     * @param array<string, string> $data
     * @param string[] $fields
     */
    public function testAnEmptyRequiredFieldIsNamedAndNothingIsSaved(array $data, array $fields): void
    {
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', $data);

        self::assertSame($fields, array_column($errors, 'field'));
        self::assertSame(
            array_fill(0, count($fields), 'Subdomain and username are required.'),
            array_column($errors, 'message')
        );
        self::assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{array<string, string>, string[]}>
     */
    public static function emptyFieldProvider(): array
    {
        return [
            'subdomain' => [['subdomain' => '', 'username' => 'api-user'], ['subdomain']],
            'username' => [['subdomain' => 'demo', 'username' => ' '], ['username']],
            'both' => [['subdomain' => '', 'username' => ''], ['subdomain', 'username']],
        ];
    }

    /**
     * PRO-3660: the storefront address is saved normalized at the website's
     * scope, and the save tells that it changed.
     */
    public function testAStorefrontUrlIsSavedNormalizedAtWebsiteScope(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $this->config->method('getStorefrontUrl')->willReturn('');

        $errors = $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'storefront_url' => ' https://Shop.Example.com/ ',
        ]);

        self::assertSame([], $errors);
        self::assertSame('https://shop.example.com', $this->savedValue(Config::XML_PATH_STOREFRONT_URL));
        self::assertSame(
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 2],
            $this->savedScope(Config::XML_PATH_STOREFRONT_URL)
        );
        self::assertTrue($this->saver->isStorefrontUrlChanged());
    }

    /**
     * PRO-3802: the initial setup's Connect step posts the Storefront URL
     * with the first credentials, as the setup's script collects them; the
     * one connection save stores it at the website's scope with them, so it
     * is saved before the Contacts step's save switches contact sync on.
     */
    public function testTheInitialSetupsConnectStepSavesTheStorefrontUrlWithTheCredentials(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $this->config->method('getStorefrontUrl')->willReturn('');

        $errors = $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'password' => 'secret',
            'multilingual_mode' => 'single',
            'storefront_url' => 'https://shop.example.com',
        ]);

        self::assertSame([], $errors);
        foreach ([Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_PASSWORD, Config::XML_PATH_STOREFRONT_URL] as $path) {
            self::assertTrue($this->wasSavedAtScope($path, ScopeInterface::SCOPE_WEBSITES, 2), $path);
        }
        self::assertSame('https://shop.example.com', $this->savedValue(Config::XML_PATH_STOREFRONT_URL));
        self::assertFalse($this->wasSaved(Config::XML_PATH_SYNC_ENABLED));
    }

    /**
     * @dataProvider unchangedStorefrontUrlProvider
     */
    public function testSavingTheSameStorefrontUrlIsNoChange(string $saved, string $posted): void
    {
        $this->config->method('getStorefrontUrl')->willReturn($saved);

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'storefront_url' => $posted]);

        self::assertFalse($this->saver->isStorefrontUrlChanged());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unchangedStorefrontUrlProvider(): array
    {
        return [
            'same address' => ['https://shop.example.com', 'https://shop.example.com/'],
            'still empty' => ['', ''],
        ];
    }

    public function testClearingTheStorefrontUrlIsAChange(): void
    {
        $this->config->method('getStorefrontUrl')->willReturn('https://shop.example.com');

        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'storefront_url' => '']);

        self::assertSame('', $this->savedValue(Config::XML_PATH_STOREFRONT_URL));
        self::assertTrue($this->saver->isStorefrontUrlChanged());
    }

    public function testAConnectionSaveWithoutTheStorefrontUrlLeavesItAsIs(): void
    {
        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user']);

        self::assertFalse($this->wasSaved(Config::XML_PATH_STOREFRONT_URL));
        self::assertFalse($this->saver->isStorefrontUrlChanged());
    }

    /**
     * PRO-3660: an address that is not an https host alone is refused on its
     * field (PRO-3562) before anything is saved or checked.
     *
     * @dataProvider refusedStorefrontUrlProvider
     */
    public function testAStorefrontUrlThatIsNotAnHttpsHostIsRefusedAndNothingIsSaved(mixed $value): void
    {
        $this->clientFactory->expects(self::never())->method('create');

        $errors = $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'storefront_url' => $value,
        ]);

        self::assertSame([[
            'field' => 'storefront_url',
            'message' => 'Enter the storefront\'s address only, starting with https:// — for example '
                . 'https://shop.example.com — without a path or a query.',
        ]], $errors);
        self::assertSame([], $this->saved);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function refusedStorefrontUrlProvider(): array
    {
        return [
            'not https' => ['http://shop.example.com'],
            'a path' => ['https://shop.example.com/products'],
            'a query' => ['https://shop.example.com/?ref=1'],
            'not a string' => [['https://shop.example.com']],
        ];
    }

    /**
     * The mode-A fallback language is saved for the target website, as the
     * fallback account it names is (PRO-3719) — another website's save does
     * not change it.
     */
    public function testFallbackLanguageIsSavedAtTheTargetWebsitesScope(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(3);

        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'fallback_language' => 'en',
        ]);

        self::assertSame(
            ['scope' => ScopeInterface::SCOPE_WEBSITES, 'scopeId' => 3],
            $this->savedScope(Config::XML_PATH_FALLBACK_LANGUAGE)
        );
        self::assertSame('en', $this->savedValue(Config::XML_PATH_FALLBACK_LANGUAGE));
    }

    /**
     * PRO-1460: mode-A per-language accounts resolve their store views
     * through the target website (docs/internal/RFC_MULTI_WEBSITE.md §2) — the resolver
     * is asked for THIS save's website, not an installation-wide scan.
     */
    public function testModeAPerLanguageAccountsResolveStoreViewsWithinTheTargetWebsite(): void
    {
        $this->accountResolver->expects(self::once())
            ->method('storeLanguages')
            ->with(0)
            ->willReturn([5 => 'et']);

        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'multilingual_mode' => 'a',
            'accounts' => [
                ['language' => 'et', 'subdomain' => 'demo-et', 'username' => 'et-user', 'password' => 'et-secret'],
            ],
        ]);

        self::assertTrue($this->wasSavedAtScope(Config::XML_PATH_USERNAME, ScopeInterface::SCOPE_STORES, 5));
    }

    /**
     * A per-language block is compared with the account of the first store
     * view of its language in the website's store view order — the store
     * view AccountResolver::storeIdForAccountKey() names — not with a later
     * one or the lowest id.
     *
     * @dataProvider representativeStoreViewProvider
     * @param array<int, string> $storeLanguages
     */
    public function testAPerLanguageBlockIsComparedWithTheFirstStoreViewOfItsLanguage(
        array $storeLanguages,
        bool $refused
    ): void {
        $config = $this->createMock(Config::class);
        $config->method('getSubdomain')->willReturnCallback(
            static fn (?int $storeId = null): string => $storeId === 4 ? 'fi-shop' : 'demo'
        );
        $config->method('getUsername')->willReturn('api-user');
        $config->method('getWebsiteSubdomain')->willReturn('demo');
        $config->method('getWebsiteUsername')->willReturn('api-user');
        $this->accountResolver->method('storeLanguages')->with(0)->willReturn($storeLanguages);
        $normalizer = $this->createMock(SubdomainNormalizer::class);
        $normalizer->method('normalize')->willReturnArgument(0);
        $saver = new WizardStepSaver(
            $this->configWriter,
            $this->createMock(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            $normalizer,
            $this->accountResolver,
            $config,
            $this->websiteContext,
            $this->createMock(StoreManagerInterface::class),
            $this->mappingSaver,
            $this->clientProvider,
            new ConfigRowNormalizer(),
            new CredentialCheck($this->clientFactory, $config),
            $this->setupNotice
        );

        $errors = $saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'multilingual_mode' => 'a',
            'accounts' => [
                ['language' => 'fi', 'subdomain' => 'fi-shop', 'username' => 'api-user', 'password' => ''],
            ],
        ]);

        self::assertSame($refused ? ['accounts.fi.password'] : [], array_column($errors, 'field'));
    }

    /**
     * @return array<string, array{0: array<int, string>, 1: bool}>
     */
    public static function representativeStoreViewProvider(): array
    {
        return [
            'the first fi store view holds the account' => [[2 => 'et', 4 => 'fi', 1 => 'fi'], false],
            'a later fi store view holds the account' => [[1 => 'fi', 4 => 'fi'], true],
        ];
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
     * PRO-1462 (docs/internal/RFC_MULTI_WEBSITE.md §6, Phase 3): the automation-mapping
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
     * Engine tenant scoping is Phase 4 (docs/internal/RFC_MULTI_WEBSITE.md §3) and RSS is
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
     * PRO-1461 (docs/internal/RFC_MULTI_WEBSITE.md §2, Phase 2): the setup-completed flag
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

    /**
     * PRO-3628: finishing the initial setup marks the "ready to set up"
     * admin notice read; saving any other step leaves it alone.
     */
    public function testFinishMarksTheSetupNoticeRead(): void
    {
        $this->setupNotice->expects(self::once())->method('markRead');

        $this->saver->save('rss', ['rss_enabled' => true]);
        $this->saver->save('finish', []);
    }
}
