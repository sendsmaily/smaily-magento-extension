<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Backfill\ContactAudience;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\ConsentSource;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory as MappingCollectionFactory;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\ViewModel\Adminhtml\WizardData;

/**
 * PRO-1462 (RFC_MULTI_WEBSITE.md §6, Phase 3): the Automations tab's prefill
 * must see what the same phase's write path now saves — a website-scoped
 * mapping row — while still falling back to a legacy website_id=0 row (the
 * 2.8.x migration / a pre-Phase-3 save) for a key the target website hasn't
 * saved its own row for, mirroring `Model\Automation\Router`'s
 * website-beats-global preference.
 */
class WizardDataTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 7;

    private WizardData $viewModel;

    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Support/Stub/ProductCollectionFactory.php';

        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::WEBSITE_ID);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturn(null);

        $this->viewModel = new WizardData(
            $this->objectManager->get(Config::class),
            $this->objectManager->get(Mode::class),
            $this->createMock(EngineSettings::class),
            $this->objectManager->get(ScopeConfigInterface::class),
            $this->createMock(AccountResolver::class),
            $this->createMock(ContactAudience::class),
            $this->createMock(OrderCollectionFactory::class),
            $this->createMock(ProductCollectionFactory::class),
            $this->objectManager->get(Json::class),
            $this->objectManager->get(MappingCollectionFactory::class),
            new WebsiteContext($storeManager, $request),
            $this->createMock(VerifiedCredentials::class),
            $this->createMock(ResolverInterface::class),
            $this->createMock(ConsentSource::class),
            $this->createMock(OrderOrigin::class)
        );
    }

    public function testWebsiteRowWinsOverLegacyGlobalRowForTheSameKey(): void
    {
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 0,
            'trigger_type' => 'welcome',
            'language' => 'default',
            'account_key' => 'default',
            'workflow_id' => 900,
            'is_default_fallback' => 1,
        ]);
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => self::WEBSITE_ID,
            'trigger_type' => 'welcome',
            'language' => 'default',
            'account_key' => 'default',
            'workflow_id' => 123,
            'is_default_fallback' => 0,
        ]);

        $mappings = $this->viewModel->getSavedMappings();

        self::assertSame(123, $mappings['welcome|default']['workflowId']);
    }

    public function testLegacyGlobalRowIsUsedAsFallbackWhenTheWebsiteHasNoRowOfItsOwn(): void
    {
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 0,
            'trigger_type' => 'abandoned_cart',
            'language' => 'default',
            'account_key' => 'default',
            'workflow_id' => 900,
            'is_default_fallback' => 1,
        ]);

        $mappings = $this->viewModel->getSavedMappings();

        self::assertSame(900, $mappings['abandoned_cart|default']['workflowId']);
        self::assertTrue($mappings['abandoned_cart|default']['isDefaultFallback']);
    }
}
