<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientFactory;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\Handler\ContactSyncHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\SubdomainNormalizer;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-189: a website with its own Smaily account (website-scope
 * smaily_connect/connection/* rows) starts its workflows and syncs its
 * contacts through that account; a website that follows the store-wide
 * account uses the store-wide one, and a per-language account its own.
 * Real core_config_data rows read with Magento's store -> website ->
 * default fallback, real smaily_automation_mapping rows, the real Router,
 * handlers, SmailyClientProvider and queue flush; only the HTTP client and
 * the store views' languages are faked.
 *
 * Websites: Main (1, store view 1) follows the store-wide account; Own (2,
 * store view 2) holds its own account; Languages (3, store views 31 ET and
 * 32 LV) has per-language accounts (mode A), ET the default fallback;
 * Inherits (4, store view 4) holds another account than the store-wide one
 * and no automations of its own — as an install from before CON-190 could;
 * Same (5, store view 5) holds the store-wide account's login of its own,
 * as a 2.8.x upgrade often saved it on every website.
 *
 * CON-190: a website on another account than the store-wide one has only
 * its own automations, so Inherits starts none of the store-wide workflows
 * through its account. The account's identity decides, not the scope its
 * login is saved at: Same starts them as before, and so does every website
 * where no store-wide account is saved.
 */
class WebsiteOwnAccountTest extends IntegrationTestCase
{
    private const MAIN = 1;
    private const OWN = 2;
    private const LANGUAGES = 3;
    private const INHERITS = 4;
    private const SAME = 5;

    private const STORES = [
        1 => self::MAIN,
        2 => self::OWN,
        31 => self::LANGUAGES,
        32 => self::LANGUAGES,
        4 => self::INHERITS,
        5 => self::SAME,
    ];

    /** @var list<array{account: string, endpoint: string, workflow: int|null}> what reached Smaily */
    private array $posted = [];

    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->posted = [];

        $this->account('default', 0, 'store-wide');
        // The store-wide First order workflow, picked from the store-wide account.
        $this->seed('default', 0, [
            Config::XML_PATH_MULTILINGUAL_MODE => 'b',
            Config::XML_PATH_FIRST_ORDER_WORKFLOW => '150',
        ]);
        $this->account('websites', self::OWN, 'website-own');
        $this->account('websites', self::INHERITS, 'website-inherits');
        // The store-wide account, its subdomain written in another case.
        $this->account('websites', self::SAME, 'Store-Wide', 'store-wide-user');
        $this->seed('websites', self::OWN, [Config::XML_PATH_FIRST_ORDER_WORKFLOW => '250']);
        // Mode A: the website's account is the default fallback (ET) account.
        $this->account('websites', self::LANGUAGES, 'et-account');
        $this->seed('websites', self::LANGUAGES, [
            Config::XML_PATH_MULTILINGUAL_MODE => 'a',
            Config::XML_PATH_FALLBACK_LANGUAGE => 'et',
        ]);
        $this->account('stores', 31, 'et-account');
        $this->account('stores', 32, 'lv-account');

        // The store-wide Welcome workflow, picked from the store-wide account.
        $this->mapping(0, Trigger::WELCOME, 'en', 'default', 100);
        // Own's own Welcome workflow, picked from its own account.
        $this->mapping(self::OWN, Trigger::WELCOME, 'en', 'default', 200);
        // Languages: LV's own Welcome workflow, and the 2.8.x migration's
        // language-less Abandoned cart row (the default fallback).
        $this->mapping(self::LANGUAGES, Trigger::WELCOME, 'lv', 'lv', 320);
        $this->mapping(self::LANGUAGES, Trigger::ABANDONED_CART, 'default', 'default', 300, 1);
    }

    public function testAWebsiteWithItsOwnAccountStartsItsWorkflowThroughThatAccount(): void
    {
        $this->automation(Trigger::WELCOME, 2, 'en');

        $this->flush();

        self::assertSame(
            [['account' => 'website-own', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 200]],
            $this->posted
        );
        self::assertSame(Event::STATUS_SENT, $this->fetchAll(EventResource::TABLE_NAME)[0]['status']);
    }

    public function testAWebsiteFollowingTheStoreWideAccountStartsItsWorkflowThroughTheStoreWideAccount(): void
    {
        $this->automation(Trigger::WELCOME, 1, 'en');

        $this->flush();

        self::assertSame(
            [['account' => 'store-wide', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 100]],
            $this->posted
        );
    }

    public function testAConfigDefaultWorkflowGoesThroughTheWebsitesOwnAccount(): void
    {
        $this->seed('websites', self::OWN, [Config::XML_PATH_MULTILINGUAL_MODE => 'single']);
        $this->automation(Trigger::FIRST_ORDER, 2, 'en');

        $this->flush();

        self::assertSame(
            [['account' => 'website-own', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 250]],
            $this->posted
        );
    }

    /**
     * CON-190: the store-wide Welcome row and the store-wide First order
     * workflow were picked from the store-wide account. A website with its
     * own account that holds no automations of its own starts neither
     * through its account: the rows are closed as Skipped, naming it.
     */
    public function testAWebsiteWithItsOwnAccountStartsNoStoreWideWorkflow(): void
    {
        $this->automation(Trigger::WELCOME, 4, 'en');
        $this->automation(Trigger::FIRST_ORDER, 4, 'en');

        $this->flush();

        self::assertSame([], $this->posted);
        foreach ($this->fetchAll(EventResource::TABLE_NAME) as $row) {
            self::assertSame(Event::STATUS_SENT, $row['status'], 'Closed, not retried');
            self::assertNull($row['sent_payload']);
            self::assertSame(
                str_replace('%1', 'Inherits', AutomationHandler::SKIPPED_NO_OWN_ACCOUNT_WORKFLOW),
                $row['last_error']
            );
        }
    }

    /**
     * CON-190: a website's own login of the store-wide account is the same
     * account, so the workflows saved for all websites keep starting there,
     * through its own login.
     */
    public function testAWebsiteWithItsOwnLoginOfTheStoreWideAccountKeepsTheStoreWideWorkflows(): void
    {
        $this->automation(Trigger::WELCOME, 5, 'en');
        $this->automation(Trigger::FIRST_ORDER, 5, 'en');

        $this->flush();

        self::assertSame(
            [
                ['account' => 'Store-Wide', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 100],
                ['account' => 'Store-Wide', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 150],
            ],
            $this->posted
        );
    }

    /**
     * CON-190: with no store-wide account saved (only website logins, a
     * 2.8.x shape), which account the workflows saved for all websites were
     * picked from is not known, so they keep starting as before — here
     * through Inherits' own account.
     */
    public function testWithNoStoreWideAccountTheWorkflowsSavedForAllWebsitesKeepStarting(): void
    {
        $this->connection->delete('core_config_data', [
            'scope = ?' => 'default',
            'path IN (?)' => [Config::XML_PATH_SUBDOMAIN, Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD],
        ]);
        $this->automation(Trigger::WELCOME, 4, 'en');
        $this->automation(Trigger::FIRST_ORDER, 4, 'en');

        $this->flush();

        self::assertSame(
            [
                ['account' => 'website-inherits', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 100],
                ['account' => 'website-inherits', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 150],
            ],
            $this->posted
        );
    }

    /**
     * CON-190: once Inherits has a workflow of its own, it starts it
     * through its own account.
     */
    public function testAWebsiteWithItsOwnAccountStartsItsOwnWorkflowThroughThatAccount(): void
    {
        $this->seed('websites', self::INHERITS, [Config::XML_PATH_FIRST_ORDER_WORKFLOW => '450']);
        $this->automation(Trigger::FIRST_ORDER, 4, 'en');

        $this->flush();

        self::assertSame(
            [['account' => 'website-inherits', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 450]],
            $this->posted
        );
    }

    /**
     * The store-wide workflows still start on a website that follows the
     * store-wide account (CON-190 changes nothing there).
     */
    public function testAWebsiteFollowingTheStoreWideAccountStartsTheStoreWideConfigWorkflow(): void
    {
        $this->automation(Trigger::FIRST_ORDER, 1, 'en');

        $this->flush();

        self::assertSame(
            [['account' => 'store-wide', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 150]],
            $this->posted
        );
    }

    public function testAPerLanguageAccountKeepsPostingThroughTheLanguagesAccount(): void
    {
        $this->automation(Trigger::WELCOME, 32, 'lv');

        $this->flush();

        self::assertSame(
            [['account' => 'lv-account', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 320]],
            $this->posted
        );
    }

    public function testALanguageLessRowOfPerLanguageAccountsGoesThroughTheWebsitesAccount(): void
    {
        $this->automation(Trigger::ABANDONED_CART, 32, 'lv');

        $this->flush();

        self::assertSame(
            [['account' => 'et-account', 'endpoint' => SmailyClient::ENDPOINT_AUTORESPONDER, 'workflow' => 300]],
            $this->posted,
            'The website\'s account (the default fallback account), not the store-wide one'
        );
    }

    public function testTheContactSyncOfAWebsiteUsesItsOwnAccount(): void
    {
        foreach ([1 => 'main@example.com', 2 => 'own@example.com'] as $storeId => $email) {
            $this->queue->enqueue(
                EventType::CONTACT_SYNC,
                ['store_id' => $storeId, 'contact' => ['email' => $email]],
                $email,
                self::STORES[$storeId]
            );
        }

        $this->flush();

        self::assertEqualsCanonicalizing(
            [
                ['account' => 'store-wide', 'endpoint' => SmailyClient::ENDPOINT_CONTACT, 'workflow' => null],
                ['account' => 'website-own', 'endpoint' => SmailyClient::ENDPOINT_CONTACT, 'workflow' => null],
            ],
            $this->posted
        );
    }

    private function automation(string $trigger, int $storeId, string $language): void
    {
        $email = $language . '-' . $storeId . '@example.com';
        $this->queue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            ['trigger_type' => $trigger, 'store_id' => $storeId, 'website_id' => self::STORES[$storeId],
                'language' => $language, 'address' => ['email' => $email]],
            $email,
            self::STORES[$storeId]
        );
    }

    private function flush(): void
    {
        $config = $this->objectManager->create(Config::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, self::STORES),
        ]);
        $clientFactory = $this->createMock(SmailyClientFactory::class);
        $clientFactory->method('create')->willReturnCallback(function (array $data): SmailyClient {
            // Each account's password is its subdomain's: a mixed-up pair fails here.
            self::assertSame($data['subdomain'] . '-secret', $data['password']);
            $account = $data['subdomain'];
            $client = $this->createMock(SmailyClient::class);
            $client->method('post')->willReturnCallback(
                function (string $endpoint, array $payload) use ($account): array {
                    $this->posted[] = [
                        'account' => $account,
                        'endpoint' => $endpoint,
                        'workflow' => $payload['autoresponder'] ?? null,
                    ];

                    return [];
                }
            );

            return $client;
        });
        $clientProvider = new SmailyClientProvider($config, $clientFactory);
        $accountResolver = $this->createMock(AccountResolver::class);
        $accountResolver->method('storeIdForAccountKey')->willReturnCallback(
            static fn (string $accountKey, int $websiteId): ?int
                => $websiteId === self::LANGUAGES ? (['et' => 31, 'lv' => 32][$accountKey] ?? null) : null
        );

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
                    'router' => $this->objectManager->create(Router::class, [
                        'config' => $config,
                        'overrides' => $overrides,
                    ]),
                    'clientProvider' => $clientProvider,
                    'accountResolver' => $accountResolver,
                    'config' => $config,
                    'overrides' => $overrides,
                    'storeManager' => $storeManager,
                ]),
                EventType::CONTACT_SYNC => $this->objectManager->create(ContactSyncHandler::class, [
                    'clientProvider' => $clientProvider,
                ]),
            ]),
        ]);
        $cron->execute();
    }

    /**
     * The store's five websites, named as in the class comment.
     */
    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        foreach ([
            self::MAIN => 'Main',
            self::OWN => 'Own',
            self::LANGUAGES => 'Languages',
            self::INHERITS => 'Inherits',
            self::SAME => 'Same',
        ] as $websiteId => $name) {
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

    /**
     * A Smaily account's three rows at a scope: subdomain, username (by
     * default "<subdomain>-user") and the encrypted password
     * ("<subdomain>-secret").
     */
    private function account(string $scope, int $scopeId, string $subdomain, ?string $username = null): void
    {
        $this->seed($scope, $scopeId, [
            Config::XML_PATH_SUBDOMAIN => $subdomain,
            Config::XML_PATH_USERNAME => $username ?? $subdomain . '-user',
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

    private function mapping(
        int $websiteId,
        string $trigger,
        string $language,
        string $accountKey,
        int $workflowId,
        int $isDefaultFallback = 0
    ): void {
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => $websiteId,
            'trigger_type' => $trigger,
            'language' => $language,
            'account_key' => $accountKey,
            'workflow_id' => $workflowId,
            'is_default_fallback' => $isDefaultFallback,
        ]);
    }
}
