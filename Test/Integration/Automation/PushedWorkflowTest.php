<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\PushedWorkflow;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Discount\AutomationDiscount;
use Smaily\Connect\Model\Discount\CodeIssuer;
use Smaily\Connect\Model\Engine\EmailDesignWorkflows;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-258: a Welcome email starts with the workflow Campaign Intelligence
 * pushed to Smaily only through the Smaily account that holds it and only
 * in the website's default language (the WooCommerce plugin's CON-218).
 * The §23 list is kept in core_config_data by the real
 * EmailDesignWorkflows and read back at the queue flush; the real Router,
 * PushedWorkflow, AutomationHandler, SmailyClientProvider and Config read
 * real rows. Only the HTTP client and the store views are faked.
 *
 * Websites: Main (1) follows the store-wide account "shop" with one
 * workflow per language (mode b): store view 1 Estonian (the default), 2
 * English. Own (2, store view 3) holds the account "other-shop" and one
 * workflow for every language.
 */
class PushedWorkflowTest extends IntegrationTestCase
{
    private const MAIN = 1;
    private const OWN = 2;

    /** Store view => website. */
    private const STORES = [1 => self::MAIN, 2 => self::MAIN, 3 => self::OWN];

    private const ET_WELCOME = 100;
    private const EN_WELCOME = 110;
    private const OWN_WELCOME = 250;
    private const PUSHED = 900;

    /** @var list<array{account: string, workflow: int}> what reached Smaily */
    private array $posted = [];

    private EventQueue $queue;

    private DatabaseScopeConfig $scopeConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->scopeConfig = new DatabaseScopeConfig($this->connection, self::STORES);
        $this->posted = [];

        $this->account('default', 0, 'shop');
        $this->seed('default', 0, [Config::XML_PATH_MULTILINGUAL_MODE => 'b']);
        $this->account('websites', self::OWN, 'other-shop');
        $this->seed('websites', self::OWN, [
            Config::XML_PATH_MULTILINGUAL_MODE => 'single',
            Config::XML_PATH_WELCOME_WORKFLOW => (string)self::OWN_WELCOME,
        ]);
        $this->mapping(Trigger::WELCOME, 'et', self::ET_WELCOME);
        $this->mapping(Trigger::WELCOME, 'en', self::EN_WELCOME);
    }

    public function testTheDefaultLanguageOnTheNamedAccountStartsThePushedWorkflow(): void
    {
        $this->push('SHOP');
        $this->automation(1, 'et');

        $this->flush();

        self::assertSame([['account' => 'shop', 'workflow' => self::PUSHED]], $this->posted);
    }

    public function testAnotherLanguageOnTheNamedAccountKeepsItsWorkflow(): void
    {
        $this->push('shop');
        $this->automation(2, 'en');

        $this->flush();

        self::assertSame([['account' => 'shop', 'workflow' => self::EN_WELCOME]], $this->posted);
    }

    public function testAWebsiteOnAnotherAccountKeepsItsWorkflow(): void
    {
        $this->push('shop');
        $this->automation(3, 'et');

        $this->flush();

        self::assertSame([['account' => 'other-shop', 'workflow' => self::OWN_WELCOME]], $this->posted);
    }

    public function testAWorkflowPushedToTheOtherAccountGoesOutOnlyThere(): void
    {
        $this->push('other-shop');
        $this->automation(1, 'et');
        $this->automation(3, 'et');

        $this->flush();

        self::assertSame(
            [
                ['account' => 'shop', 'workflow' => self::ET_WELCOME],
                ['account' => 'other-shop', 'workflow' => self::PUSHED],
            ],
            $this->posted
        );
    }

    public function testNullIdsKeepEveryWorkflow(): void
    {
        $this->workflows()->remember([[
            'email_key' => 'plugin.welcome',
            'kind' => 'plugin',
            'sent_through' => 'workflow',
            'smaily_state' => 'not_available',
            'smaily_workflow_id' => null,
            'smaily_subdomain' => null,
            'created_by' => null,
        ]]);
        $this->automation(1, 'et');
        $this->automation(3, 'et');

        $this->flush();

        self::assertSame(
            [
                ['account' => 'shop', 'workflow' => self::ET_WELCOME],
                ['account' => 'other-shop', 'workflow' => self::OWN_WELCOME],
            ],
            $this->posted
        );
    }

    /** A §23 read: the Welcome email pushed to the account `$subdomain`. */
    private function push(string $subdomain): void
    {
        $this->workflows()->remember([[
            'email_key' => 'plugin.welcome',
            'kind' => 'plugin',
            'sent_through' => 'workflow',
            'design_state' => 'edited',
            'smaily_state' => 'pushed',
            'smaily_template_id' => '123',
            'smaily_workflow_id' => (string)self::PUSHED,
            'smaily_subdomain' => $subdomain,
            'created_by' => 'engine',
        ]]);
    }

    private function workflows(): EmailDesignWorkflows
    {
        return new EmailDesignWorkflows(
            $this->scopeConfig,
            $this->objectManager->get(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            new Json()
        );
    }

    private function automation(int $storeId, string $language): void
    {
        $email = $language . '-' . $storeId . '@example.com';
        $this->queue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            ['trigger_type' => Trigger::WELCOME, 'store_id' => $storeId, 'website_id' => self::STORES[$storeId],
                'language' => $language, 'address' => ['email' => $email]],
            $email,
            $storeId
        );
    }

    private function flush(): void
    {
        $config = $this->objectManager->create(Config::class, ['scopeConfig' => $this->scopeConfig]);
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->method('create')->willReturnCallback(function (array $data): SmailyClient {
            $account = $data['subdomain'];
            $client = $this->createMock(SmailyClient::class);
            $client->method('subdomain')->willReturn($account);
            $client->method('post')->willReturnCallback(
                function (string $endpoint, array $payload) use ($account): array {
                    $this->posted[] = ['account' => $account, 'workflow' => $payload['autoresponder']];

                    return [];
                }
            );

            return $client;
        });
        $accountResolver = $this->createMock(AccountResolver::class);
        // Both websites' default store views are Estonian.
        $accountResolver->method('defaultLanguage')->willReturn('et');

        $storeManager = $this->storeManager();
        $overrides = new WebsiteOverrides(
            $this->objectManager->get(ResourceConnection::class),
            $this->objectManager->get(WriterInterface::class),
            $storeManager,
            new SubdomainNormalizer()
        );

        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([
                EventType::AUTOMATION_TRIGGER => $this->objectManager->create(AutomationHandler::class, [
                    // No discount code: the automation's switch is off (CON-198).
                    'discount' => $this->objectManager->create(AutomationDiscount::class, [
                        'issuer' => $this->createMock(CodeIssuer::class),
                    ]),
                    'router' => $this->objectManager->create(Router::class, [
                        'config' => $config,
                        'overrides' => $overrides,
                    ]),
                    'clientProvider' => new SmailyClientProvider($config, $clientFactory),
                    'accountResolver' => $accountResolver,
                    'config' => $config,
                    'overrides' => $overrides,
                    'storeManager' => $storeManager,
                    'pushedWorkflow' => new PushedWorkflow($this->workflows()),
                ]),
            ]),
        ]);
        $cron->execute();
    }

    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([self::MAIN => 'Main', self::OWN => 'Own'] as $websiteId => $name) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($websiteId);
            $website->method('getName')->willReturn($name);
            $websites[$websiteId] = $website;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (int $websiteId): WebsiteInterface => $websites[$websiteId]
        );

        return $storeManager;
    }

    /** A Smaily account's three rows at a scope. */
    private function account(string $scope, int $scopeId, string $subdomain): void
    {
        $this->seed($scope, $scopeId, [
            Config::XML_PATH_SUBDOMAIN => $subdomain,
            Config::XML_PATH_USERNAME => $subdomain . '-user',
            Config::XML_PATH_PASSWORD => $this->objectManager->get(EncryptorInterface::class)
                ->encrypt($subdomain . '-secret'),
        ]);
    }

    /**
     * @param array<string, string> $values path => value
     */
    private function seed(string $scope, int $scopeId, array $values): void
    {
        foreach ($values as $path => $value) {
            $this->connection->insertOnDuplicate('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ], ['value']);
        }
    }

    /** A store-wide row of the website's one account (mode b). */
    private function mapping(string $trigger, string $language, int $workflowId): void
    {
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => 0,
            'trigger_type' => $trigger,
            'language' => $language,
            'account_key' => 'default',
            'workflow_id' => $workflowId,
            'is_default_fallback' => 0,
        ]);
    }
}
