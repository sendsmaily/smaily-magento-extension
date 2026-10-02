<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel\Adminhtml;

use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Customer\Model\ResourceModel\Customer\Collection as CustomerCollection;
use Magento\Customer\Model\ResourceModel\Customer\CollectionFactory as CustomerCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\ResourceModel\Order\Collection as OrderCollection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;
use Smaily\Connect\ViewModel\Adminhtml\WizardData;

/**
 * PRO-1461 (RFC_MULTI_WEBSITE.md §2, Phase 2): every prefill path the
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

    private WizardData $viewModel;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../../Support/Stub/CustomerCollectionFactory.php';
        require_once __DIR__ . '/../../Support/Stub/ProductCollectionFactory.php';

        $this->config = $this->createMock(Config::class);
        $this->mode = $this->createMock(Mode::class);
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->accountResolver = $this->createMock(AccountResolver::class);
        $this->accountResolver->method('detectedLanguages')->willReturn([]);
        $this->websiteContext = $this->createMock(WebsiteContext::class);
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $this->websiteContext->method('getStoreId')->willReturn(5);
        $this->verifiedCredentials = $this->createMock(VerifiedCredentials::class);

        $customerCollection = $this->createMock(CustomerCollection::class);
        $customerCollection->method('getSize')->willReturn(0);
        $customerCollectionFactory = $this->createMock(CustomerCollectionFactory::class);
        $customerCollectionFactory->method('create')->willReturn($customerCollection);

        $orderCollection = $this->createMock(OrderCollection::class);
        $orderCollection->method('getSize')->willReturn(0);
        $orderCollectionFactory = $this->createMock(OrderCollectionFactory::class);
        $orderCollectionFactory->method('create')->willReturn($orderCollection);

        $productCollection = $this->createMock(ProductCollection::class);
        $productCollection->method('getSize')->willReturn(0);
        $productCollectionFactory = $this->createMock(ProductCollectionFactory::class);
        $productCollectionFactory->method('create')->willReturn($productCollection);

        $this->viewModel = new WizardData(
            $this->config,
            $this->mode,
            $this->createMock(EngineSettings::class),
            $this->scopeConfig,
            $this->accountResolver,
            $customerCollectionFactory,
            $orderCollectionFactory,
            $productCollectionFactory,
            new Json(),
            $this->createMock(MappingCollectionFactory::class),
            $this->websiteContext,
            $this->verifiedCredentials
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
     * PRO-3560: filled-in credentials are not "Connected" — the Connection
     * status follows whether Smaily accepted them at the last real check.
     */
    public function testSavedCredentialsSmailyHasNotAcceptedAreNotShownAsConnected(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->config->method('isConnected')->willReturn(true);
        $this->verifiedCredentials->expects(self::once())->method('isVerified')->with(5)->willReturn(false);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['connected']);
        self::assertFalse($decoded['verified']);
    }

    public function testSavedCredentialsSmailyAcceptedAreShownAsConnected(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->verifiedCredentials->method('isVerified')->with(5)->willReturn(true);

        $decoded = json_decode($this->viewModel->getBootJson(), true);

        self::assertTrue($decoded['verified']);
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
}
