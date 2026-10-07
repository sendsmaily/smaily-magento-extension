<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Magento\Config\Model\ResourceModel\Config as ConfigResource;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ConfigResource\ConfigInterface as ConfigResourceInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\Writer as ConfigStorageWriter;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Reader as DeploymentConfigReader;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ObjectManager as AppObjectManager;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\ResourceConnection\ConfigInterface as ResourceConfigInterface;
use Magento\Framework\App\ResourceConnection\ConnectionAdapterInterface;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Config\ScopeInterface as ConfigScopeInterface;
use Magento\Framework\Data\Collection\Db\FetchStrategy\Query as FetchStrategyQuery;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\Data\Collection\EntityFactory as CollectionEntityFactory;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\DataObject\IdentityGeneratorInterface;
use Magento\Framework\DataObject\IdentityService;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Logger\Quiet as QuietDbLogger;
use Magento\Framework\DB\LoggerInterface as DbLoggerInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\DB\Select\ColumnsRenderer;
use Magento\Framework\DB\Select\DistinctRenderer;
use Magento\Framework\DB\Select\ForUpdateRenderer;
use Magento\Framework\DB\Select\FromRenderer;
use Magento\Framework\DB\Select\GroupRenderer;
use Magento\Framework\DB\Select\HavingRenderer;
use Magento\Framework\DB\Select\LimitRenderer;
use Magento\Framework\DB\Select\OrderRenderer;
use Magento\Framework\DB\Select\SelectRenderer;
use Magento\Framework\DB\Select\UnionRenderer;
use Magento\Framework\DB\Select\WhereRenderer;
use Magento\Framework\DB\SelectFactory;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\MessageQueue\PoisonPill\PoisonPillPutInterface;
use Magento\Framework\Model\ResourceModel\Db\TransactionManager;
use Magento\Framework\Model\ResourceModel\Db\TransactionManagerInterface;
use Magento\Framework\Model\ResourceModel\Type\Db\ConnectionFactory as ModelConnectionFactory;
use Magento\Framework\Model\ResourceModel\Type\Db\ConnectionFactoryInterface as ModelConnectionFactoryInterface;
use Magento\Framework\Model\ResourceModel\Type\Db\Pdo\Mysql as PdoMysqlConnectionAdapter;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Framework\ObjectManager\Config\Config as ObjectManagerConfig;
use Magento\Framework\ObjectManager\Factory\Dynamic\Developer as DeveloperFactory;
use Magento\Framework\ObjectManager\ObjectManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Setup\SchemaListener;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Smaily\Connect\Test\Integration\Support\Fake\FakeCache;
use Smaily\Connect\Test\Integration\Support\Fake\FakeConfigScope;
use Smaily\Connect\Test\Integration\Support\Fake\FakeEventManager;
use Smaily\Connect\Test\Integration\Support\Fake\FakeNotifier;
use Smaily\Connect\Test\Integration\Support\Fake\FakePoisonPillPut;
use Smaily\Connect\Test\Integration\Support\Fake\FakeResourceConfig;
use Smaily\Connect\Test\Integration\Support\Fake\FakeScopeConfig;
use Smaily\Connect\Test\Integration\Support\Fake\FakeTimezone;

/**
 * Standalone Magento object graph for integration tests.
 *
 * Boots a real Magento\Framework model/resource-model/collection layer on a
 * real MySQL connection (Developer-mode object manager, auto-wired from
 * vendor/magento/framework) WITHOUT a full Magento application: no modules,
 * no declarative-schema install, no search engine. App-level services that
 * the persistence layer merely touches (event manager, cache, locale) are
 * no-op fakes; everything on the SQL path — DB adapter, Select machinery,
 * AbstractDb save/duplicate handling, encryptor, config writer — is the
 * real framework code.
 *
 * Connection parameters come from SMAILY_IT_DB_* environment variables
 * (see TESTING.md); the schema is dropped and reinstalled from
 * etc/db_schema.xml once per process.
 */
class TestEnvironment
{
    private const ENV_DEFAULTS = [
        'SMAILY_IT_DB_HOST' => '127.0.0.1',
        'SMAILY_IT_DB_PORT' => '3306',
        'SMAILY_IT_DB_USER' => 'root',
        'SMAILY_IT_DB_PASSWORD' => 'root',
        'SMAILY_IT_DB_NAME' => 'smaily_connect_it',
    ];

    private const CRYPT_KEY = 'ff00112233445566778899aabbccddee';

    /**
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * @var ObjectManagerInterface
     */
    private ObjectManagerInterface $objectManager;

    /**
     * @var array<string, object>
     */
    private array $sharedInstances = [];

    private function __construct()
    {
        $db = $this->databaseConfig();
        $this->ensureDatabaseExists($db);
        $this->objectManager = $this->buildObjectManager($db);
        (new SchemaInstaller($this->getConnection()))
            ->install(dirname(__DIR__, 3) . '/etc/db_schema.xml');
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getObjectManager(): ObjectManagerInterface
    {
        return $this->objectManager;
    }

    public function getConnection(): AdapterInterface
    {
        /** @var ResourceConnection $resource */
        $resource = $this->objectManager->get(ResourceConnection::class);

        return $resource->getConnection();
    }

    public function getClock(): TestClock
    {
        /** @var TestClock $clock */
        $clock = $this->objectManager->get(TestClock::class);

        return $clock;
    }

    public function getNotifier(): FakeNotifier
    {
        /** @var FakeNotifier $notifier */
        $notifier = $this->objectManager->get(FakeNotifier::class);

        return $notifier;
    }

    public function getScopeConfig(): FakeScopeConfig
    {
        /** @var FakeScopeConfig $scopeConfig */
        $scopeConfig = $this->objectManager->get(FakeScopeConfig::class);

        return $scopeConfig;
    }

    /**
     * Reset mutable state between tests: module tables, config rows,
     * captured notifications, the frozen clock.
     */
    public function resetState(): void
    {
        $connection = $this->getConnection();
        // Tests (and the schema patch under test) run raw DDL; the adapter's
        // in-memory describeTable cache must not leak across tests.
        $connection->resetDdlCache();
        $tables = [
            'smaily_event_queue',
            'smaily_ingest_queue',
            'smaily_abandoned_cart',
            'smaily_automation_mapping',
            'smaily_backfill_job',
            'smaily_order_attribution',
            'core_config_data',
        ];
        foreach ($tables as $table) {
            $connection->truncateTable($table);
        }

        $this->getClock()->reset();
        $this->getNotifier()->reset();
        $this->getScopeConfig()->reset();
    }

    /**
     * @return array{host: string, port: string, user: string, password: string, dbname: string}
     */
    private function databaseConfig(): array
    {
        $env = [];
        foreach (self::ENV_DEFAULTS as $name => $default) {
            $value = getenv($name);
            $env[$name] = $value === false || $value === '' ? $default : $value;
        }

        return [
            'host' => $env['SMAILY_IT_DB_HOST'],
            'port' => $env['SMAILY_IT_DB_PORT'],
            'user' => $env['SMAILY_IT_DB_USER'],
            'password' => $env['SMAILY_IT_DB_PASSWORD'],
            'dbname' => $env['SMAILY_IT_DB_NAME'],
        ];
    }

    /**
     * Fail fast with actionable instructions when MySQL is unreachable.
     *
     * @param array{host: string, port: string, user: string, password: string, dbname: string} $db
     */
    private function ensureDatabaseExists(array $db): void
    {
        try {
            $pdo = new \PDO(
                sprintf('mysql:host=%s;port=%s', $db['host'], $db['port']),
                $db['user'],
                $db['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
            );
        } catch (\PDOException $exception) {
            throw new \RuntimeException(sprintf(
                "Integration test MySQL is not reachable at %s:%s (%s).\n"
                . 'Start one, e.g.: docker run --rm -d --name smaily-it-mysql '
                . '-e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=smaily_connect_it '
                . "-p 3306:3306 mysql:8.4\n"
                . 'and/or point SMAILY_IT_DB_HOST/PORT/USER/PASSWORD/NAME at it (see TESTING.md).',
                $db['host'],
                $db['port'],
                $exception->getMessage()
            ), 0, $exception);
        }

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4',
            str_replace('`', '', $db['dbname'])
        ));
    }

    /**
     * @param array{host: string, port: string, user: string, password: string, dbname: string} $db
     */
    private function buildObjectManager(array $db): ObjectManagerInterface
    {
        $config = new ObjectManagerConfig();
        $config->extend([
            'preferences' => [
                LoggerInterface::class => NullLogger::class,
                EventManagerInterface::class => FakeEventManager::class,
                CacheInterface::class => FakeCache::class,
                ConfigScopeInterface::class => FakeConfigScope::class,
                ScopeConfigInterface::class => FakeScopeConfig::class,
                SerializerInterface::class => Json::class,
                DbLoggerInterface::class => QuietDbLogger::class,
                IdentityGeneratorInterface::class => IdentityService::class,
                TransactionManagerInterface::class => TransactionManager::class,
                EntityFactoryInterface::class => CollectionEntityFactory::class,
                FetchStrategyInterface::class => FetchStrategyQuery::class,
                ModelConnectionFactoryInterface::class => ModelConnectionFactory::class,
                ConnectionAdapterInterface::class => PdoMysqlConnectionAdapter::class,
                ResourceConfigInterface::class => FakeResourceConfig::class,
                WriterInterface::class => ConfigStorageWriter::class,
                ConfigResourceInterface::class => ConfigResource::class,
                PoisonPillPutInterface::class => FakePoisonPillPut::class,
                EncryptorInterface::class => Encryptor::class,
                TimezoneInterface::class => FakeTimezone::class,
                NotifierInterface::class => FakeNotifier::class,
            ],
            // The DB adapter lazily creates a SchemaListener on DDL calls;
            // its required constructor arrays have no runtime DI config here.
            SchemaListener::class => [
                'arguments' => ['definitionMappers' => [], 'handlers' => []],
            ],
        ]);

        $factory = new DeveloperFactory($config);
        $objectManager = new ObjectManager($factory, $config, $this->sharedInstances);
        $factory->setObjectManager($objectManager);
        // Framework classes fall back to the global instance (collections
        // resolving their resource model, optional constructor arguments).
        AppObjectManager::setInstance($objectManager);

        $this->share(DeploymentConfig::class, $this->buildDeploymentConfig($db));
        $this->share(SelectFactory::class, $this->buildSelectFactory($objectManager));

        $clock = new TestClock(new FakeTimezone());
        $this->share(TestClock::class, $clock);
        $this->share(DateTime::class, $clock);

        // Single instances reachable both via the interface (auto-wiring)
        // and via the concrete class (test assertions/mutations).
        $notifier = new FakeNotifier();
        $this->share(NotifierInterface::class, $notifier);
        $this->share(FakeNotifier::class, $notifier);

        $scopeConfig = new FakeScopeConfig();
        $this->share(ScopeConfigInterface::class, $scopeConfig);
        $this->share(FakeScopeConfig::class, $scopeConfig);

        return $objectManager;
    }

    private function share(string $type, object $instance): void
    {
        $this->sharedInstances[ltrim($type, '\\')] = $instance;
    }

    /**
     * Real DeploymentConfig backed by a temporary app/etc/env.php carrying
     * the DB connection and a crypt key (exercises the real Encryptor).
     *
     * @param array{host: string, port: string, user: string, password: string, dbname: string} $db
     */
    private function buildDeploymentConfig(array $db): DeploymentConfig
    {
        $root = sys_get_temp_dir() . '/smaily-connect-integration-' . getmypid();
        $etcDir = $root . '/app/etc';
        if (!is_dir($etcDir) && !mkdir($etcDir, 0777, true) && !is_dir($etcDir)) {
            throw new \RuntimeException('Unable to create temporary config directory ' . $etcDir);
        }

        $env = [
            'db' => [
                'connection' => [
                    'default' => [
                        'host' => $db['host'] . ':' . $db['port'],
                        'dbname' => $db['dbname'],
                        'username' => $db['user'],
                        'password' => $db['password'],
                        'active' => '1',
                        'engine' => 'innodb',
                        'initStatements' => 'SET NAMES utf8mb4;',
                    ],
                ],
            ],
            'crypt' => ['key' => self::CRYPT_KEY],
        ];
        file_put_contents($etcDir . '/env.php', '<?php return ' . var_export($env, true) . ';');
        file_put_contents($etcDir . '/config.php', '<?php return ' . var_export(['modules' => []], true) . ';');

        return new DeploymentConfig(
            new DeploymentConfigReader(new DirectoryList($root), new DriverPool(), new ConfigFilePool())
        );
    }

    /**
     * SelectFactory with the standard renderer chain (in production this
     * comes from app/etc/di.xml, which module-only installs do not have).
     */
    private function buildSelectFactory(ObjectManagerInterface $objectManager): SelectFactory
    {
        $renderer = static fn (string $class, int $sort, string $part): array => [
            'renderer' => $objectManager->get($class),
            'sort' => $sort,
            'part' => $part,
        ];

        // Created through the object manager (like production di.xml does):
        // the renderer chain is an array of renderer/sort/part descriptors.
        $selectRenderer = $objectManager->create(SelectRenderer::class, [
            'renderers' => [
                'distinct' => $renderer(DistinctRenderer::class, 100, Select::DISTINCT),
                'columns' => $renderer(ColumnsRenderer::class, 200, Select::COLUMNS),
                'union' => $renderer(UnionRenderer::class, 300, Select::UNION),
                'from' => $renderer(FromRenderer::class, 400, Select::FROM),
                'where' => $renderer(WhereRenderer::class, 500, Select::WHERE),
                'group' => $renderer(GroupRenderer::class, 600, Select::GROUP),
                'having' => $renderer(HavingRenderer::class, 700, Select::HAVING),
                'order' => $renderer(OrderRenderer::class, 800, Select::ORDER),
                'limit' => $renderer(LimitRenderer::class, 900, Select::LIMIT_COUNT),
                'for_update' => $renderer(ForUpdateRenderer::class, 1000, Select::FOR_UPDATE),
            ],
        ]);

        return new SelectFactory($selectRenderer);
    }
}
