<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Backfill;

use Magento\Backend\App\Action\Context as ActionContext;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Console\Command\BackfillStartCommand;
use Smaily\Connect\Controller\Adminhtml\Api\BackfillState;
use Smaily\Connect\Controller\Adminhtml\Api\EngineExchange;
use Smaily\Connect\Model\Backfill\EngineImportGuard;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Backfill\ImportStarter;
use Smaily\Connect\Model\Backfill\HistoryProgress;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Backfill\JobStatusAggregator;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Backend\EngineSetupToken;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connector;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\Collection;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\FakeTimezone;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * CON-156 against a real MySQL: the admin and the command line share one
 * connect step (Engine\Connector) and one import start (ImportStarter), so
 * each pair of callers leaves the same rows — the same stored connection
 * and the same queued jobs, in the same order — from the same start.
 *
 * Both callers of a pair run at the same frozen instant: the jobs'
 * started_at and completed_at come from the application clock, and a pair
 * whose second run began a wall-clock second later used to differ there
 * (CON-215).
 */
class SharedConnectAndImportStartTest extends IntegrationTestCase
{
    private const JOB_TABLE = 'smaily_backfill_job';

    private const EXCHANGE = [
        'api_key' => 'sk_live_abc',
        'tenant_id' => 'tn_1',
        'tenant_name' => 'Pilot',
        'engine_base_url' => 'https://engine.example/v1',
        'engine_version' => '1.8.3',
        'endpoints' => ['catalog' => 'https://engine.example/v1/catalog'],
        'config' => ['browse_tracking' => true],
        'issued_at' => '2026-10-23T08:00:00Z',
    ];

    /** @var int the frozen instant every run of the test starts from */
    private int $startedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = $this->clock->now();
    }

    /**
     * @dataProvider connectStartStates
     */
    public function testTheAdminConnectAndTheConfigurationSaveLeaveTheSameRows(bool $catalogQueued): void
    {
        $admin = $this->afterConnect(
            $catalogQueued,
            fn (Connector $connector) => $this->engineExchange($connector)->execute()
        );
        $configSave = $this->afterConnect(
            $catalogQueued,
            fn (Connector $connector) => $this->engineSetupToken($connector)->beforeSave()
        );

        self::assertSame($admin, $configSave);
        self::assertSame(
            [Job::TYPE_CATALOG, Job::TYPE_CUSTOMERS, Job::TYPE_ORDERS],
            array_column($admin['jobs'], 'job_type'),
            'The catalog import, then the customers import, then the orders import'
        );
        self::assertContains(
            ['path' => EngineSettings::XML_PATH_CONNECTED, 'value' => '1'],
            $admin['config']
        );
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function connectStartStates(): array
    {
        return [
            'nothing queued' => [false],
            'a catalog import already queued' => [true],
        ];
    }

    /**
     * @dataProvider importStartStates
     */
    public function testTheAdminStartAndTheCommandLeaveTheSameJobs(
        string $jobType,
        bool $connected,
        string $before,
        int $expectedRows
    ): void {
        $admin = $this->afterImportStart(
            $connected,
            $before,
            fn (ImportStarter $starter) => $this->backfillState($starter, $jobType)->execute()
        );
        $command = $this->afterImportStart(
            $connected,
            $before,
            fn (ImportStarter $starter) => (new CommandTester(new BackfillStartCommand(
                $this->objectManager->get(JobManager::class),
                $starter,
                $this->createMock(Config::class)
            )))->execute(['type' => $jobType])
        );

        self::assertSame($admin, $command);
        self::assertCount($expectedRows, $admin);
    }

    /**
     * @return array<string, array{string, bool, string, int}>
     */
    public static function importStartStates(): array
    {
        return [
            'catalog, connected' => [Job::TYPE_CATALOG, true, 'none', 1],
            'catalog, not connected' => [Job::TYPE_CATALOG, false, 'none', 0],
            'orders, not connected' => [Job::TYPE_ORDERS, false, 'none', 0],
            'catalog, a stalled one running' => [Job::TYPE_CATALOG, true, 'stalled catalog', 2],
            'contacts, every website' => [Job::TYPE_CONTACTS, false, 'none', 2],
            'contacts, website 2 already queued' => [Job::TYPE_CONTACTS, true, 'contacts on website 2', 2],
        ];
    }

    /**
     * Connect from an empty store and return what it stored and queued.
     *
     * @param callable(Connector): mixed $connect
     * @return array{jobs: array<int, array<string, mixed>>, config: array<int, array<string, mixed>>}
     */
    private function afterConnect(bool $catalogQueued, callable $connect): array
    {
        $this->resetState();
        $jobManager = $this->objectManager->create(JobManager::class);
        if ($catalogQueued) {
            $jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
        }

        $client = $this->createMock(Client::class);
        $client->method('setupExchange')->with('tok_abc123')->willReturn(self::EXCHANGE);
        $connect(new Connector(
            $client,
            $this->objectManager->create(EngineSettings::class, [
                'cacheTypeList' => $this->createMock(TypeListInterface::class),
            ]),
            new ImportsOnConnect($jobManager)
        ));

        $config = [];
        foreach ($this->fetchAll('core_config_data', 'path') as $row) {
            // Encrypted with a fresh IV each time: equal in meaning, not in bytes.
            $config[] = [
                'path' => $row['path'],
                'value' => $row['path'] === EngineSettings::XML_PATH_API_KEY ? '(encrypted)' : $row['value'],
            ];
        }

        return ['jobs' => $this->jobRows(), 'config' => $config];
    }

    /**
     * Start an import from the given state and return the job rows.
     *
     * @param callable(ImportStarter): mixed $start
     * @return array<int, array<string, mixed>>
     */
    private function afterImportStart(bool $connected, string $before, callable $start): array
    {
        $this->resetState();
        $jobManager = $this->objectManager->create(JobManager::class);
        if ($before === 'stalled catalog') {
            $stalled = $jobManager->startIfIdle(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID);
            self::assertNotNull($stalled);
            $jobManager->markRunning($stalled);
            $this->connection->update(
                self::JOB_TABLE,
                ['updated_at' => $this->clockDate(-JobManager::STALLED_SECONDS - 1)],
                ['id = ?' => (int)$stalled->getId()]
            );
        } elseif ($before === 'contacts on website 2') {
            $jobManager->startIfIdle(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 2);
        }

        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn($connected);
        $start(new ImportStarter($jobManager, $this->storeManager(), new EngineImportGuard($engineSettings)));

        return $this->jobRows();
    }

    /**
     * Empty the tables and set the clock back to the test's start instant,
     * which resetState() moves on to the wall clock's.
     */
    private function resetState(): void
    {
        $this->env->resetState();
        $this->clock->travel($this->startedAt - $this->clock->now());
    }

    /**
     * The job rows without the timestamps MySQL stamps from its own clock.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jobRows(): array
    {
        return array_map(
            static fn (array $row): array => array_diff_key($row, ['created_at' => true, 'updated_at' => true]),
            $this->fetchAll(self::JOB_TABLE)
        );
    }

    private function engineExchange(Connector $connector): EngineExchange
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode(['setup_url' => 'tok_abc123']));
        $context = $this->createMock(ActionContext::class);
        $context->method('getRequest')->willReturn($request);

        return new EngineExchange($context, $this->jsonFactory(), new JsonSerializer(), $connector);
    }

    private function engineSetupToken(Connector $connector): EngineSetupToken
    {
        $context = $this->createMock(ModelContext::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(EventManager::class));
        $model = new EngineSetupToken(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(MessageManager::class),
            $connector
        );
        $model->setValue('tok_abc123');

        return $model;
    }

    private function backfillState(ImportStarter $starter, string $jobType): BackfillState
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn((string)json_encode(['action' => 'start', 'job_type' => $jobType]));
        $context = $this->createMock(ActionContext::class);
        $context->method('getRequest')->willReturn($request);

        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getItems')->willReturn([]);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        return new BackfillState(
            $context,
            $this->jsonFactory(),
            new JsonSerializer(),
            $this->objectManager->get(JobManager::class),
            $collectionFactory,
            $this->storeManager(),
            new FakeTimezone(),
            new JobStatusAggregator(),
            $starter,
            $this->createMock(HistoryProgress::class)
        );
    }

    private function jsonFactory(): JsonFactory
    {
        $result = $this->createMock(Json::class);
        $result->method('setData')->willReturnSelf();
        $jsonFactory = $this->createMock(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($result);

        return $jsonFactory;
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([1, 2] as $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }

        return $this->createConfiguredMock(StoreManagerInterface::class, ['getWebsites' => $websites]);
    }
}
