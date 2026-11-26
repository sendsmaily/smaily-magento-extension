<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\CatalogManifest;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;
use Smaily\Connect\ViewModel\Adminhtml\DashboardData;

/**
 * CON-281: under separate stores the Dashboard follows the website
 * selector, as Settings does — the selected website's Smaily account and
 * the marketing queue's figures of its contacts and automations, the
 * default website's when none is selected; Campaign Intelligence's rows stay
 * the whole installation's. Under one store nothing changes. Against real
 * config and queue tables.
 *
 * Two websites: Main (1, the default website) and Second (7).
 */
class DashboardDataTest extends IntegrationTestCase
{
    private const MAIN = 1;
    private const SECOND = 7;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->insertRow('websites', self::MAIN, Config::XML_PATH_SUBDOMAIN, 'main-shop');
        $this->insertRow('websites', self::SECOND, Config::XML_PATH_SUBDOMAIN, 'second-shop');
        $today = $this->clockDate();
        $this->smailyRow(self::MAIN, 'sent', $today);
        $this->smailyRow(self::MAIN, 'sent', $today);
        $this->smailyRow(self::MAIN, 'pending', $today);
        $this->smailyRow(self::SECOND, 'sent', $today);
        $this->smailyRow(self::SECOND, 'failed', $today, EventType::AUTOMATION_TRIGGER);
        $this->connection->insert(IngestEventResource::TABLE_NAME, [
            'domain' => 'catalog',
            'entity_id' => '1',
            'event_uuid' => 'ingest-1',
            'payload' => '{}',
            'status' => 'pending',
            'created_at' => $today,
            'updated_at' => $today,
        ]);
    }

    public function testASeparateStoresDashboardShowsTheSelectedWebsite(): void
    {
        $this->insertRow('default', 0, StoreLayout::XML_PATH_SEPARATE_STORES, '1');

        $dashboard = $this->dashboard((string)self::SECOND);

        self::assertSame('second-shop', $dashboard->getSmailySubdomain());
        self::assertSame(1, $dashboard->getContactSyncsDelivered());
        self::assertSame(1, $dashboard->getFailedLast24h(), 'Its automation that failed');
        self::assertSame(1, $dashboard->getQueuedToday(), 'Campaign Intelligence\'s row only');
        self::assertSame(
            ['intelligence', 'smaily', 'smaily'],
            $this->sources($dashboard),
            'Its own Smaily rows and Campaign Intelligence\'s'
        );
        self::assertSame(['_query' => ['website' => self::SECOND]], $dashboard->getWebsiteQuery());
    }

    public function testASeparateStoresDashboardWithoutASelectedWebsiteShowsTheDefaultWebsite(): void
    {
        $this->insertRow('default', 0, StoreLayout::XML_PATH_SEPARATE_STORES, '1');

        $dashboard = $this->dashboard(null);

        self::assertSame('main-shop', $dashboard->getSmailySubdomain());
        self::assertSame(2, $dashboard->getContactSyncsDelivered());
        self::assertSame(0, $dashboard->getFailedLast24h());
        self::assertSame(2, $dashboard->getQueuedToday());
        self::assertSame(['_query' => ['website' => self::MAIN]], $dashboard->getWebsiteQuery());
    }

    public function testAOneStoreDashboardShowsTheWholeInstallation(): void
    {
        $this->insertRow('default', 0, Config::XML_PATH_SUBDOMAIN, 'shop');
        $this->insertRow('default', 0, Config::XML_PATH_USERNAME, 'api-user');

        $dashboard = $this->dashboard((string)self::SECOND);

        self::assertSame(3, $dashboard->getContactSyncsDelivered());
        self::assertSame(1, $dashboard->getFailedLast24h());
        self::assertSame(2, $dashboard->getQueuedToday());
        self::assertCount(6, $dashboard->getRecentActivity());
        self::assertSame([], $dashboard->getWebsiteQuery());
    }

    /**
     * The Dashboard as a request with this `website` parameter sees it.
     */
    private function dashboard(?string $websiteParam): DashboardData
    {
        $storeManager = $this->storeManager();
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('website')->willReturn($websiteParam);
        $config = $this->objectManager->create(Config::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, [self::MAIN => self::MAIN, self::SECOND => self::SECOND]),
        ]);

        return $this->objectManager->create(DashboardData::class, [
            'config' => $config,
            'engineSettings' => $this->createMock(EngineSettings::class),
            'setupGuard' => $this->createMock(SetupGuard::class),
            'flagManager' => $this->createMock(FlagManager::class),
            'verifiedCredentials' => $this->createMock(VerifiedCredentials::class),
            'catalogManifest' => $this->createMock(CatalogManifest::class),
            'websiteContext' => new WebsiteContext($storeManager, $request),
            'storeLayout' => $this->storeLayoutFor($storeManager),
        ]);
    }

    /**
     * @return StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($websiteId);
            $website = $this->createMock(Website::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getName')->willReturn('Website ' . $websiteId);
            $website->method('getStoreIds')->willReturn([$websiteId]);
            $website->method('getDefaultStore')->willReturn($store);
            $websites[$websiteId] = $website;
        }
        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::MAIN);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (int $websiteId) => $websites[$websiteId] ?? null
        );

        return $storeManager;
    }

    /**
     * @return string[] the sources of the recent activity's rows, sorted
     */
    private function sources(DashboardData $dashboard): array
    {
        $sources = array_column($dashboard->getRecentActivity(), 'source');
        sort($sources);

        return $sources;
    }

    private function smailyRow(
        int $websiteId,
        string $status,
        string $at,
        string $eventType = EventType::CONTACT_SYNC
    ): void {
        $this->connection->insert(EventResource::TABLE_NAME, [
            'event_type' => $eventType,
            'entity_id' => 'test@example.com',
            'event_uuid' => 'dash-' . ++$this->sequence,
            'website_id' => $websiteId,
            'payload' => '{}',
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    private function insertRow(string $scope, int $scopeId, string $path, string $value): void
    {
        $this->connection->insert('core_config_data', [
            'scope' => $scope,
            'scope_id' => $scopeId,
            'path' => $path,
            'value' => $value,
        ]);
    }
}
