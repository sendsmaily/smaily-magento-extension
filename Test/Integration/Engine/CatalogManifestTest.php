<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use Magento\Catalog\Model\Product;
use Magento\Framework\FlagManager;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\CatalogIngest;
use Smaily\Connect\Model\Engine\CatalogManifest;
use Smaily\Connect\Model\Engine\CatalogProductLoader;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Engine\Payload\CatalogPayloadBuilder;
use Smaily\Connect\Model\Engine\Queue\IngestEvent;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\SchemaInstaller;

/**
 * PRO-3854 against a real MySQL: the nightly catalog manifest's waits read
 * the real ingest queue and import job tables, and each night that sends
 * leaves one row in the real Log, which is never sent again. The product
 * list itself comes from a stubbed catalog page here; the page's own
 * select runs against real product tables in CatalogProductLoaderTest, and
 * its key and stock parity with the catalog sync is unit-tested
 * (CatalogPayloadBuilderTest) and checked on the sandbox catalog. The
 * nights without a list are counted in Magento's real flag table
 * (PRO-3914).
 */
class CatalogManifestTest extends IntegrationTestCase
{
    private const ANSWER = [
        'ok' => true,
        'products_in_manifest' => 3,
        'removed' => 1,
        'stock_fixed' => 2,
        'missing_in_engine' => 0,
        'guard_tripped' => false,
        'guard_reason' => null,
        'would_remove' => 1,
    ];

    /** @var array<int, array<int, array{sku: string, in_stock: bool}>> each request's list */
    private array $sent = [];

    private ?\Exception $failure = null;

    private int $catalogSize = 4;

    protected function setUp(): void
    {
        parent::setUp();
        (new SchemaInstaller($this->connection))->createFlag();
    }

    public function testANightThatSendsLeavesOneLogRowWithTheEnginesAnswer(): void
    {
        $this->manifest()->send();

        self::assertSame([[
            ['sku' => 'SKU-1', 'in_stock' => true],
            ['sku' => 'SKU-3', 'in_stock' => true],
            ['sku' => 'mag-4', 'in_stock' => false],
        ]], $this->sent, 'product 2 is disabled');
        $rows = $this->fetchAll(IngestEventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(CatalogManifest::DOMAIN, $row['domain']);
        self::assertSame(IngestEvent::STATUS_SENT, $row['status']);
        self::assertSame(['products' => 3, 'in_stock' => 2], json_decode((string)$row['payload'], true));
        self::assertSame(['products' => $this->sent[0]], json_decode((string)$row['sent_payload'], true));
        self::assertSame(
            ['http_status' => 200, 'body' => self::ANSWER],
            json_decode((string)$row['last_response'], true)
        );

        $log = $this->objectManager->create(Collection::class)->getItems();
        self::assertCount(1, $log);
        self::assertSame(CatalogManifest::DOMAIN, reset($log)->getData('type'));
    }

    public function testAFailedNightIsParkedAndNeverSentAgain(): void
    {
        $this->failure = new EngineTransportException('Engine request failed with HTTP 503 after retries', 503);

        $this->manifest()->send();

        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status']);
        self::assertSame('1', (string)$row['attempts']);
        self::assertNull($row['next_retry_at']);
        $guard = $this->objectManager->create(ResendGuard::class);
        $loaded = $this->objectManager->create(QueueRowLoader::class)
            ->loadFailed(Collection::SOURCE_INTELLIGENCE, [(int)$row['id']]);
        self::assertSame(
            [(int)$row['id'] => ResendGuard::REASON_NIGHTLY],
            $guard->refusalReasons(Collection::SOURCE_INTELLIGENCE, $loaded)
        );
    }

    public function testAStoreOverTheLimitSendsNothingAndTheLogSaysWhy(): void
    {
        // One more enabled product than the list can hold: product 2 is disabled.
        $this->catalogSize = CatalogManifest::MAX_PRODUCTS + 2;

        $this->manifest()->send();

        self::assertCount(0, $this->sent);
        $row = $this->fetchAll(IngestEventResource::TABLE_NAME)[0];
        self::assertSame(IngestEvent::STATUS_FAILED, $row['status']);
        self::assertSame(CatalogManifest::TOO_MANY_PRODUCTS, $row['last_error']);
        self::assertNull($row['sent_payload']);
    }

    public function testAStoreAtTheLimitSendsItsWholeListInOneRequest(): void
    {
        // Exactly 50,000 enabled products: product 2 is disabled.
        $this->catalogSize = CatalogManifest::MAX_PRODUCTS + 1;

        $this->manifest()->send();

        self::assertCount(1, $this->sent);
        self::assertCount(CatalogManifest::MAX_PRODUCTS, $this->sent[0]);
        self::assertSame('SKU-50001', end($this->sent[0])['sku']);
        self::assertSame(
            IngestEvent::STATUS_SENT,
            $this->fetchAll(IngestEventResource::TABLE_NAME)[0]['status']
        );
    }

    /**
     * Catalog rows, removals and stock-change markers on their way hold the
     * list back; a parked (failed) catalog row does not — it would hold the
     * list back forever, and healing what it left wrong is the list's job.
     */
    public function testCatalogChangesOnTheirWayHoldTheListBack(): void
    {
        foreach ([
            [Client::DOMAIN_CATALOG, IngestEvent::STATUS_PENDING],
            [Client::DOMAIN_CATALOG_REMOVE, IngestEvent::STATUS_SENDING],
            [CatalogIngest::DOMAIN_CHANGED, IngestEvent::STATUS_PENDING],
        ] as [$domain, $status]) {
            $this->queueRow($domain, $status);
            $this->manifest()->send();
            self::assertSame([], $this->sent, $domain);
            $this->connection->delete(IngestEventResource::TABLE_NAME);
        }
        // Three nights without a list, for the Dashboard (PRO-3914).
        $flags = $this->objectManager->create(FlagManager::class);
        self::assertSame(
            ['nights' => 3, 'reason' => CatalogManifest::REASON_QUEUE],
            $flags->getFlagData(CatalogManifest::FLAG_UNSENT)
        );

        $this->queueRow(Client::DOMAIN_CATALOG, IngestEvent::STATUS_FAILED);
        $this->queueRow(Client::DOMAIN_ORDERS, IngestEvent::STATUS_PENDING);
        $this->manifest()->send();
        self::assertCount(1, $this->sent);
        self::assertNull($this->objectManager->create(FlagManager::class)->getFlagData(CatalogManifest::FLAG_UNSENT));
    }

    /**
     * Woo PRO-3886's lesson: an import that nothing moves any more must not
     * hold the list back forever.
     */
    public function testARunningCatalogImportHoldsTheListBackUntilItStalls(): void
    {
        $jobManager = $this->objectManager->create(JobManager::class);
        $job = $jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $jobManager->markRunning($job);
        $this->jobMovedAt((int)$job->getId(), -120);

        $this->manifest()->send();
        self::assertSame([], $this->sent);

        $this->jobMovedAt((int)$job->getId(), -CatalogManifest::STALLED_IMPORT_SECONDS - 1);
        $this->manifest()->send();
        self::assertCount(1, $this->sent);
    }

    /**
     * PRO-3950: a catalog import the tick has set aside does not move, however
     * busy the imports behind it keep the worker, so it holds nothing back;
     * one queued behind an import that moves still does.
     */
    public function testASetAsideCatalogImportDoesNotHoldTheListBackWhileOtherImportsRun(): void
    {
        $jobManager = $this->objectManager->create(JobManager::class);
        $catalog = $jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $jobManager->markRunning($catalog);
        $this->jobMovedAt((int)$catalog->getId(), -CatalogManifest::STALLED_IMPORT_SECONDS - 1);
        $contacts = $jobManager->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1);
        $jobManager->markRunning($contacts);
        $this->jobMovedAt((int)$contacts->getId(), -30);

        $this->manifest()->send();
        self::assertCount(1, $this->sent, 'the set-aside import holds nothing back');

        $jobManager->requestCancel(Job::TYPE_CATALOG, Job::TARGET_ENGINE);
        $jobManager->start(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        $this->manifest()->send();
        self::assertCount(1, $this->sent, 'a queued import behind one that moves still does');
    }

    public function testNothingIsSentWhileSendingIsNotAllowed(): void
    {
        $this->manifest(sendingAllowed: false)->send();

        self::assertSame([], $this->sent);
        self::assertSame([], $this->fetchAll(IngestEventResource::TABLE_NAME));
    }

    private function manifest(bool $sendingAllowed = true): CatalogManifest
    {
        $settings = $this->createMock(Settings::class);
        $settings->method('isSendingAllowed')->willReturn($sendingAllowed);

        $client = $this->createMock(Client::class);
        $lastList = null;
        $client->method('catalogManifest')->willReturnCallback(function (array $products) use (&$lastList): array {
            $lastList = $products;
            $this->sent[] = $products;
            if ($this->failure !== null) {
                throw $this->failure;
            }
            return self::ANSWER;
        });
        $client->method('lastExchange')->willReturnCallback(function () use (&$lastList): array {
            return [
                'request' => ['products' => $lastList],
                'response' => $this->failure !== null ? null : ['http_status' => 200, 'body' => self::ANSWER],
            ];
        });

        return $this->objectManager->create(CatalogManifest::class, [
            'settings' => $settings,
            'productLoader' => $this->catalogPages(),
            'payloadBuilder' => $this->itemBuilder(),
            'client' => $client,
        ]);
    }

    /**
     * Products 1..catalogSize, by entity id after the page's cursor.
     */
    private function catalogPages(): CatalogProductLoader
    {
        $loader = $this->createMock(CatalogProductLoader::class);
        $loader->method('loadForManifest')->willReturnCallback(function (int $afterId, int $pageSize): array {
            $page = [];
            for ($id = $afterId + 1; $id <= min($afterId + $pageSize, $this->catalogSize); $id++) {
                $page[] = $this->product($id);
            }

            return $page;
        });

        return $loader;
    }

    /**
     * A product that carries only its entity id — 50,001 mocks would make
     * the over-limit case slow.
     */
    private function product(int $id): Product
    {
        return new class ($id) extends Product {
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedFunction
            public function __construct(private readonly int $productId)
            {
            }

            /**
             * @inheritDoc
             */
            public function getId()
            {
                return $this->productId;
            }
        };
    }

    /**
     * Product 2 is disabled, product 4 has no sku and is out of stock. Not a
     * mock: a mock records every call, which the over-limit case cannot hold.
     */
    private function itemBuilder(): CatalogPayloadBuilder
    {
        return new class extends CatalogPayloadBuilder {
            // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedFunction
            public function __construct()
            {
            }

            /**
             * @inheritDoc
             */
            public function manifestItem(Product $product): ?array
            {
                $id = (int)$product->getId();
                return match ($id) {
                    2 => null,
                    4 => ['sku' => 'mag-4', 'in_stock' => false],
                    default => ['sku' => 'SKU-' . $id, 'in_stock' => true],
                };
            }
        };
    }

    private function queueRow(string $domain, string $status): void
    {
        $this->connection->insert(IngestEventResource::TABLE_NAME, [
            'domain' => $domain,
            'entity_id' => '7',
            'event_uuid' => uniqid('', true),
            'payload' => '{}',
            'status' => $status,
        ]);
    }

    private function jobMovedAt(int $jobId, int $offsetSeconds): void
    {
        $this->connection->update(
            'smaily_backfill_job',
            ['updated_at' => $this->clockDate($offsetSeconds)],
            ['id = ?' => $jobId]
        );
    }
}
