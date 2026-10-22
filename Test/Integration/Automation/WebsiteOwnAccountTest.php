<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Magento\Framework\Encryption\EncryptorInterface;
use Smaily\Connect\Cron\FlushEventQueue;
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
 * 32 LV) has per-language accounts (mode A), ET the default fallback.
 */
class WebsiteOwnAccountTest extends IntegrationTestCase
{
    private const MAIN = 1;
    private const OWN = 2;
    private const LANGUAGES = 3;

    private const STORES = [1 => self::MAIN, 2 => self::OWN, 31 => self::LANGUAGES, 32 => self::LANGUAGES];

    /** @var list<array{account: string, endpoint: string, workflow: int|null}> what reached Smaily */
    private array $posted = [];

    private EventQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->posted = [];

        $this->account('default', 0, 'store-wide');
        $this->seed('default', 0, [Config::XML_PATH_MULTILINGUAL_MODE => 'b']);
        $this->account('websites', self::OWN, 'website-own');
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

        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([
                EventType::AUTOMATION_TRIGGER => $this->objectManager->create(AutomationHandler::class, [
                    'router' => $this->objectManager->create(Router::class, ['config' => $config]),
                    'clientProvider' => $clientProvider,
                    'accountResolver' => $accountResolver,
                    'config' => $config,
                ]),
                EventType::CONTACT_SYNC => $this->objectManager->create(ContactSyncHandler::class, [
                    'clientProvider' => $clientProvider,
                ]),
            ]),
        ]);
        $cron->execute();
    }

    /**
     * A Smaily account's three rows at a scope: subdomain, username and the
     * encrypted password ("<subdomain>-secret").
     */
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
