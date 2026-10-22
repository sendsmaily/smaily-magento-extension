<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Automation;

use Smaily\Connect\Cron\FlushEventQueue;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\AutomationHandler;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Test\Integration\IntegrationTestCase;

/**
 * CON-188: with per-language Smaily accounts (mode A) an automation whose
 * contact's language has no workflow row never starts the config-default
 * workflow, which belongs to no per-language account and would be posted
 * through the contact's language account. It starts the workflow of the
 * default fallback account's language, through that account, and with none
 * there the row is closed as Skipped, naming the language. Real
 * smaily_automation_mapping rows, the real Router, AutomationHandler and
 * queue flush; only the Smaily client and the store views are faked.
 */
class PerLanguageAccountFallbackTest extends IntegrationTestCase
{
    private const WEBSITE_ID = 1;

    /** The store view of each language's own Smaily account. */
    private const STORE_OF = ['et' => 11, 'lv' => 12];

    private const CONFIG_DEFAULT = 9;
    private const ET_WELCOME = 101;

    private EventQueue $queue;

    /** @var array<int, array{store: int|string|null, workflow: int}> what reached Smaily */
    private array $posted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = $this->objectManager->create(EventQueue::class);
        $this->posted = [];
        $scopeConfig = $this->env->getScopeConfig();
        $scopeConfig->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'a');
        $scopeConfig->setValue(Config::XML_PATH_FALLBACK_LANGUAGE, 'et');
        // Saved before the website took per-language accounts.
        $scopeConfig->setValue(Config::XML_PATH_WELCOME_WORKFLOW, (string)self::CONFIG_DEFAULT);
        $scopeConfig->setValue(Config::XML_PATH_FIRST_ORDER_WORKFLOW, (string)self::CONFIG_DEFAULT);

        // ET (the fallback account's language) has a Welcome workflow; LV
        // has none, and no row is the default fallback.
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => self::WEBSITE_ID,
            'trigger_type' => Trigger::WELCOME,
            'language' => 'et',
            'account_key' => 'et',
            'workflow_id' => self::ET_WELCOME,
            'is_default_fallback' => 0,
        ]);
    }

    public function testALanguageWithoutAWorkflowGetsTheFallbackAccountsWorkflowThroughThatAccount(): void
    {
        $this->enqueue(Trigger::WELCOME, 'lv', 'lv@example.com');

        $this->flush();

        self::assertSame(
            [['store' => self::STORE_OF['et'], 'workflow' => self::ET_WELCOME]],
            $this->posted,
            'The ET account\'s workflow goes through the ET account, never through LV\'s'
        );
        self::assertSame(Event::STATUS_SENT, $this->fetchAll(EventResource::TABLE_NAME)[0]['status']);
    }

    public function testWithNoFallbackWorkflowEitherTheContactIsSkippedNamingTheLanguage(): void
    {
        $this->enqueue(Trigger::FIRST_ORDER, 'lv', 'lv@example.com');

        $this->flush();

        self::assertSame([], $this->posted, 'The config default is never posted through a language account');
        $row = $this->fetchAll(EventResource::TABLE_NAME)[0];
        self::assertSame(Event::STATUS_SENT, $row['status'], 'Closed: retrying cannot make a workflow appear');
        self::assertNull($row['sent_payload']);
        self::assertSame(
            str_replace('%1', 'LV', AutomationHandler::SKIPPED_NO_LANGUAGE_WORKFLOW),
            $row['last_error']
        );
    }

    public function testTheSameStoreInPerLanguageWorkflowsStillStartsTheConfigDefault(): void
    {
        $this->env->getScopeConfig()->setValue(Config::XML_PATH_MULTILINGUAL_MODE, 'b');
        $this->enqueue(Trigger::FIRST_ORDER, 'lv', 'lv@example.com');

        $this->flush();

        self::assertSame(
            [['store' => self::STORE_OF['lv'], 'workflow' => self::CONFIG_DEFAULT]],
            $this->posted,
            'Mode B has one account: the config default goes through the event\'s store view'
        );
    }

    private function enqueue(string $trigger, string $language, string $email): void
    {
        $this->queue->enqueue(
            EventType::AUTOMATION_TRIGGER,
            ['trigger_type' => $trigger, 'store_id' => self::STORE_OF[$language], 'website_id' => self::WEBSITE_ID,
                'language' => $language, 'address' => ['email' => $email]],
            $email,
            self::STORE_OF[$language],
            'con188-' . $trigger . '-' . $language
        );
    }

    private function flush(): void
    {
        $clientProvider = $this->createMock(SmailyClientProvider::class);
        $clientProvider->method('forStore')->willReturnCallback(function (int|string|null $storeId): SmailyClient {
            $client = $this->createMock(SmailyClient::class);
            $client->method('post')->willReturnCallback(
                function (string $endpoint, array $payload) use ($storeId): array {
                    $this->posted[] = ['store' => $storeId, 'workflow' => $payload['autoresponder']];

                    return [];
                }
            );

            return $client;
        });
        $accountResolver = $this->createMock(AccountResolver::class);
        $accountResolver->method('storeIdForAccountKey')->willReturnCallback(
            static fn (string $accountKey): ?int => self::STORE_OF[$accountKey] ?? null
        );

        /** @var FlushEventQueue $cron */
        $cron = $this->objectManager->create(FlushEventQueue::class, [
            'handlerPool' => new HandlerPool([
                EventType::AUTOMATION_TRIGGER => $this->objectManager->create(AutomationHandler::class, [
                    'router' => $this->objectManager->create(Router::class),
                    'clientProvider' => $clientProvider,
                    'accountResolver' => $accountResolver,
                ]),
            ]),
        ]);
        $cron->execute();
    }
}
