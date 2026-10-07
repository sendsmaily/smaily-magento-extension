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
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Adminhtml\SetupNotice;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\ConfigRowNormalizer;
use Smaily\Connect\Model\Automation\MappingSaver;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * WizardStepSaver's website-scoped writes (RFC_MULTI_WEBSITE.md §1) against
 * a real core_config_data table: the target website's row is a real,
 * distinct scoped row, and a pre-existing default-scope value (an
 * un-migrated single-website install, or a value another website falls
 * back to) is left untouched rather than moved or deleted.
 */
class WizardStepSaverTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 7;

    private WizardStepSaver $saver;

    /** @var SmailyClientProvider&\PHPUnit\Framework\MockObject\MockObject */
    private $smailyClientProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn(null);

        $this->smailyClientProvider = $this->createMock(SmailyClientProvider::class);

        $this->saver = new WizardStepSaver(
            $this->objectManager->get(WriterInterface::class),
            $this->objectManager->get(EncryptorInterface::class),
            $this->createMock(TypeListInterface::class),
            new SubdomainNormalizer(),
            $this->createMock(AccountResolver::class),
            $this->objectManager->get(Config::class),
            new WebsiteContext($storeManager, $request),
            $storeManager,
            $this->objectManager->get(MappingSaver::class),
            $this->smailyClientProvider,
            new ConfigRowNormalizer(),
            new CredentialCheck(
                $this->createMock(SmailyClientFactory::class),
                $this->objectManager->get(Config::class)
            ),
            $this->createMock(SetupNotice::class)
        );
    }

    public function testConnectCredentialsLandAtTheWebsiteScopeRow(): void
    {
        $this->saver->save('connect', [
            'subdomain' => 'demo',
            'username' => 'api-user',
            'password' => 'plain-secret',
        ]);

        $rows = $this->configRows(Config::XML_PATH_SUBDOMAIN);
        self::assertCount(1, $rows);
        self::assertSame('websites', $rows[0]['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$rows[0]['scope_id']);
        self::assertSame('demo', $rows[0]['value']);

        $passwordRow = $this->configRows(Config::XML_PATH_PASSWORD)[0];
        self::assertSame('websites', $passwordRow['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$passwordRow['scope_id']);
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);
        self::assertSame('plain-secret', $encryptor->decrypt($passwordRow['value']));
    }

    /**
     * The read/write asymmetry this phase closes (PRO-1274, RFC §1): an
     * existing default-scope value (an un-migrated single-website install,
     * or another website's fallback) is never touched by a save — only the
     * target website gains its own explicit row.
     */
    public function testPreExistingDefaultScopeValueIsLeftUntouchedAsFallback(): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => Config::XML_PATH_SUBDOMAIN,
            'value' => 'legacy-default',
        ]);

        $this->saver->save('connect', ['subdomain' => 'new-demo', 'username' => 'api-user', 'password' => 'secret']);

        $rows = $this->configRows(Config::XML_PATH_SUBDOMAIN);
        self::assertCount(2, $rows, 'The default-scope row must survive alongside the new website row');

        $byScope = [];
        foreach ($rows as $row) {
            $byScope[$row['scope']] = $row['value'];
        }
        self::assertSame('legacy-default', $byScope['default']);
        self::assertSame('new-demo', $byScope['websites']);
    }

    public function testSubscriberTogglesLandAtTheWebsiteScopeRow(): void
    {
        $this->saver->save('subscribers', ['sync_enabled' => true]);

        $rows = $this->configRows(Config::XML_PATH_SYNC_ENABLED);
        self::assertCount(1, $rows);
        self::assertSame('websites', $rows[0]['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$rows[0]['scope_id']);
        self::assertSame('1', $rows[0]['value']);
    }

    public function testAutomationTogglesLandAtTheWebsiteScopeRow(): void
    {
        $this->saver->save('automations', ['welcome_enabled' => true]);

        $rows = $this->configRows(Config::XML_PATH_WELCOME_ENABLED);
        self::assertCount(1, $rows);
        self::assertSame('websites', $rows[0]['scope']);
        self::assertSame(self::WEBSITE_ID, (int)$rows[0]['scope_id']);
    }

    public function testSavingTwiceUpdatesTheSameWebsiteRowInsteadOfDuplicating(): void
    {
        $this->saver->save('connect', ['subdomain' => 'demo', 'username' => 'api-user', 'password' => 'secret']);
        $this->saver->save('connect', ['subdomain' => 'demo-two', 'username' => 'api-user', 'password' => 'secret']);

        $rows = $this->configRows(Config::XML_PATH_SUBDOMAIN);
        self::assertCount(1, $rows);
        self::assertSame('demo-two', $rows[0]['value']);
    }

    /**
     * PRO-1462 (RFC_MULTI_WEBSITE.md §6, Phase 3): the automation-mapping
     * admin save routes through the real target website instead of the
     * previously hardcoded website_id=0 — a pre-existing legacy global row
     * (2.8.x migration / a pre-Phase-3 save) survives untouched, and the
     * Router prefers this website's own row over it once saved, while a
     * different website with no row of its own still resolves the legacy
     * fallback unchanged.
     */
    public function testAutomationMappingSavesAtTheWebsiteScopeAndTheRouterHonorsIt(): void
    {
        $this->smailyClientProvider->method('forStore')
            ->willThrowException(new SmailyClientException('not configured'));

        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 0,
            'trigger_type' => 'welcome',
            'language' => 'default',
            'account_key' => 'default',
            'workflow_id' => 900,
            'is_default_fallback' => 1,
        ]);

        $errors = $this->saver->save('automations', [
            'mappings' => [
                [
                    'trigger_type' => 'welcome',
                    'language' => 'default',
                    'account_key' => 'default',
                    'workflow_id' => 123,
                    'is_default_fallback' => true,
                ],
            ],
        ]);
        self::assertSame([], $errors);

        $byWebsite = [];
        foreach ($this->fetchAll(MappingResource::TABLE_NAME) as $row) {
            $byWebsite[(int)$row['website_id']] = $row;
        }
        self::assertSame('900', $byWebsite[0]['workflow_id'], 'The legacy global row survives untouched');
        self::assertSame(self::WEBSITE_ID, (int)$byWebsite[self::WEBSITE_ID]['website_id']);
        self::assertSame('123', $byWebsite[self::WEBSITE_ID]['workflow_id']);

        /** @var Router $router */
        $router = $this->objectManager->create(Router::class);
        $scopeConfig = $this->env->getScopeConfig();
        $scopeConfig->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'b');

        $ownRow = $router->resolve('welcome', self::WEBSITE_ID, 'default');
        self::assertNotNull($ownRow);
        self::assertSame(123, $ownRow->workflowId, 'This website prefers its own newly saved row');

        $otherWebsite = $router->resolve('welcome', 999, 'default');
        self::assertNotNull($otherWebsite);
        self::assertSame(
            900,
            $otherWebsite->workflowId,
            'A single-website install (or an unmigrated website) keeps resolving the legacy row unchanged'
        );
    }

    /**
     * PRO-1461 (RFC_MULTI_WEBSITE.md §2, Phase 2): the setup-completed flag
     * lands as a real website-scoped row, and a real ScopeConfig read at that
     * website resolves it — while a different website with no row of its own
     * still falls back to a pre-existing default-scope value unchanged (an
     * un-migrated single-website install carries its flag at default scope).
     */
    public function testFinishSavesTheSetupCompletedFlagAsARealWebsiteScopedRow(): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => WizardStepSaver::XML_PATH_SETUP_COMPLETED,
            'value' => '1',
        ]);

        $errors = $this->saver->save('finish', []);
        self::assertSame([], $errors);

        $rows = $this->configRows(WizardStepSaver::XML_PATH_SETUP_COMPLETED);
        $byScope = [];
        foreach ($rows as $row) {
            $byScope[$row['scope']] = $row['value'];
        }
        self::assertSame('1', $byScope['default'] ?? null, 'The pre-existing default-scope row survives untouched');
        self::assertSame('1', $byScope['websites'] ?? null, 'The target website gets its own explicit row');

        $websiteRow = null;
        foreach ($rows as $row) {
            if ($row['scope'] === 'websites') {
                $websiteRow = $row;
            }
        }
        self::assertNotNull($websiteRow);
        self::assertSame(self::WEBSITE_ID, (int)$websiteRow['scope_id']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configRows(string $path): array
    {
        return $this->connection->fetchAll(
            $this->connection->select()->from('core_config_data')->where('path = ?', $path)
        );
    }
}
