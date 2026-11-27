<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Setup;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\App\State;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Newsletter\Helper\Data as NewsletterHelper;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriptionManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\ResourceConnection;
use Smaily\Connect\Model\Adminhtml\WebsiteContacts;
use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
use Smaily\Connect\Model\ContactSync\UnconnectedSignup;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\ContactSync\StorefrontSubscription;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\ContactSync\SyncDispatcher;
use Smaily\Connect\Model\Multilingual\LanguageResolver;
use Smaily\Connect\Model\Queue\ContactEntity;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping as MappingResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;
use Smaily\Connect\Model\StoreLayout;
use Smaily\Connect\Model\WebsiteConnections;
use Smaily\Connect\Observer\CustomerSaveAfter;
use Smaily\Connect\Observer\OrderPlaced;
use Smaily\Connect\Observer\SubscriberSaveAfter;
use Smaily\Connect\Model\AbandonedCart\ReminderConsent;
use Smaily\Connect\Model\AbandonedCart\StateManager;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Smaily\Connect\Setup\Patch\Data\ShareSingleWebsiteSettings;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-470: a store with several websites run as one store on one Smaily
 * account whose initial setup (3.0.0-rc1 to rc12) saved the Smaily
 * account, the Contacts settings and the automations on one website only.
 * The upgrade moves them to the default scope, so every website uses them:
 * a newsletter signup on another website reaches Smaily and starts its
 * welcome automation. Websites on different Smaily accounts, separate
 * stores, per-language Smaily accounts and a section the default scope
 * already holds stay as they are. A signup on a website still without a
 * Smaily connection is recorded in the Log as skipped. On real config,
 * mapping and queue tables.
 *
 * Two websites: Main (1, store view 1) and Second (2, store view 2).
 */
class ShareSingleWebsiteSettingsTest extends IntegrationTestCase
{
    private const MAIN = 1;
    private const SECOND = 2;

    /** The setup's mark an initial setup on 3.0.0-rc1 to rc12 left on its website. */
    private const SET_UP = [self::MAIN, WizardStepSaver::XML_PATH_SETUP_COMPLETED, '1'];

    /** Its storefront address, which stays the website's own. */
    private const STOREFRONT = [self::MAIN, Config::XML_PATH_STOREFRONT_URL, 'https://shop.example.com'];

    /** @var array<int, array{0: string, 1: array<string, mixed>}> the patch's log lines */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Support/Stub/SubscriberFactory.php';
        require_once __DIR__ . '/../Support/Stub/CustomerInterfaceFactory.php';
    }

    public function testOneWebsitesSettingsApplyToEveryWebsiteAndASignupThereStartsItsWelcome(): void
    {
        $password = $this->encrypt('secret');
        $this->seedWebsite(self::MAIN, $password);

        $this->runPatch();

        // Every row moved as stored, the password still encrypted; the
        // storefront address and the setup's mark stay.
        self::assertSame([self::STOREFRONT, self::SET_UP], $this->websiteRows(), 'Nothing else of its own');
        self::assertSame([
            Config::XML_PATH_ABANDONED_ENABLED => '0',
            Config::XML_PATH_WELCOME_ENABLED => '1',
            Config::XML_PATH_WELCOME_WORKFLOW => '42',
            Config::XML_PATH_MULTILINGUAL_MODE => 'b',
            Config::XML_PATH_PASSWORD => $password,
            Config::XML_PATH_SUBDOMAIN => 'shop',
            Config::XML_PATH_USERNAME => 'api-user',
            Config::XML_PATH_CONTACTS_CONFIRMED => '1',
            Config::XML_PATH_SYNC_ENABLED => '1',
            Config::XML_PATH_SYNC_MODE => 'consent',
        ], $this->defaultRows());
        self::assertSame([[0, 'welcome', 'et', 77]], $this->mappingRows());

        $config = $this->config();
        $router = $this->objectManager->create(Router::class, [
            'config' => $config,
            'overrides' => $this->objectManager->create(WebsiteOverrides::class, ['storeManager' => $this->storeManager()]),
        ]);
        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            self::assertTrue($config->isConnected($websiteId), 'Store view ' . $websiteId);
            self::assertSame('secret', $config->getPassword($websiteId));
            self::assertTrue($config->isContactSyncActive($websiteId), 'Website ' . $websiteId);
            self::assertTrue($config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
            self::assertSame(77, $router->resolve(Trigger::WELCOME, $websiteId, 'et')?->workflowId);
            self::assertSame(42, $router->resolve(Trigger::WELCOME, $websiteId, 'en')?->workflowId);
        }

        // A newsletter signup on the second website.
        $this->observer()->execute($this->signup(self::SECOND, 'second@example.com'));

        self::assertSame([
            [EventType::CONTACT_SYNC, self::SECOND, 'pending'],
            [EventType::AUTOMATION_TRIGGER, self::SECOND, 'pending'],
        ], $this->queueRows());

        // The log line names the website and the paths, never a value.
        self::assertCount(3, $this->logged);
        foreach ($this->logged as [, $context]) {
            self::assertSame(self::MAIN, $context['website_id']);
            self::assertStringNotContainsString('secret', json_encode($context, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString($password, json_encode($context, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('api-user', json_encode($context, JSON_THROW_ON_ERROR));
        }
        self::assertSame(
            ['connection', 'subscribers', 'automations'],
            array_map(static fn (array $line): string => $line[1]['section'], $this->logged)
        );
        self::assertContains(Config::XML_PATH_CONTACTS_CONFIRMED, $this->logged[1][1]['paths']);
        self::assertContains(MappingResource::TABLE_NAME, $this->logged[2][1]['paths']);
    }

    public function testASecondRunMovesNothing(): void
    {
        $this->seedWebsite(self::MAIN, $this->encrypt('secret'));
        $this->runPatch();
        $rows = $this->defaultRows();
        $this->logged = [];

        $this->runPatch();

        self::assertSame($rows, $this->defaultRows());
        self::assertSame([self::STOREFRONT, self::SET_UP], $this->websiteRows());
        self::assertSame([], $this->logged);
    }

    /**
     * A section the default scope already holds (here the Contacts
     * settings, saved for all websites by a later setup) does not move; the
     * other sections do. The website's own Contacts settings sync another
     * mode than the ones for all websites, so they stay until the merchant
     * confirms (WebsiteContacts).
     */
    public function testASectionTheDefaultScopeHoldsStaysAsItIs(): void
    {
        $this->seedWebsite(self::MAIN, $this->encrypt('secret'));
        $this->seed('default', 0, [Config::XML_PATH_SYNC_MODE => 'legitimate_interest']);

        $this->runPatch();

        self::assertSame([
            [Config::XML_PATH_STOREFRONT_URL, 'https://shop.example.com'],
            [Config::XML_PATH_CONTACTS_CONFIRMED, '1'],
            [WizardStepSaver::XML_PATH_SETUP_COMPLETED, '1'],
            [Config::XML_PATH_SYNC_ENABLED, '1'],
            [Config::XML_PATH_SYNC_MODE, 'consent'],
        ], array_map(static fn (array $row): array => [$row[1], $row[2]], $this->websiteRows()));
        self::assertSame('shop', $this->config()->getWebsiteSubdomain(self::SECOND));
        self::assertSame('legitimate_interest', $this->config()->getSyncMode(self::SECOND));
        self::assertSame(['connection', 'automations'], array_column(array_column($this->logged, 1), 'section'));
    }

    /**
     * Separate stores, per-language Smaily accounts, two websites with
     * values of their own, a store whose initial setup is not finished (a
     * 2.8.x upgrade, which the setup asks how its websites are run), and
     * websites or a store view on different Smaily accounts stay as they
     * are. Different Smaily accounts are not one store: the Dashboard says
     * so (StoreLayout::mixesAccounts()), and no website is called
     * unconnected.
     *
     * @dataProvider untouchedStores
     *
     * @param array<int, array{0: string, 1: int, 2: string, 3: string}> $extraRows
     */
    public function testOtherStoresStayAsTheyAre(array $extraRows, bool $mixesAccounts = false): void
    {
        $this->seedWebsite(self::MAIN, $this->encrypt('secret'));
        foreach ($extraRows as [$scope, $scopeId, $path, $value]) {
            if ($value === '') {
                $this->connection->delete('core_config_data', ['path = ?' => $path]);
                continue;
            }
            $this->seed($scope, $scopeId, [$path => $value]);
        }
        $before = [$this->defaultRows(), $this->websiteRows(), $this->mappingRows()];

        $layout = $this->runPatch();

        self::assertSame($before, [$this->defaultRows(), $this->websiteRows(), $this->mappingRows()]);
        self::assertSame([], $this->logged);
        self::assertSame($mixesAccounts, $layout->mixesAccounts());
        if ($mixesAccounts) {
            self::assertSame(
                [],
                (new WebsiteConnections($this->config(), $this->storeManager()))->unconnectedWebsites()
            );
        }
    }

    /**
     * @return array<string, array{0: array<int, array{0: string, 1: int, 2: string, 3: string}>, 1?: bool}>
     */
    public static function untouchedStores(): array
    {
        return [
            'separate stores' => [[['default', 0, StoreLayout::XML_PATH_SEPARATE_STORES, '1']]],
            'per-language Smaily accounts' => [[
                ['websites', self::MAIN, Config::XML_PATH_FALLBACK_LANGUAGE, 'et'],
                ['default', 0, Config::XML_PATH_MULTILINGUAL_MODE, 'a'],
            ]],
            'two websites with values of their own' => [[
                ['websites', self::SECOND, Config::XML_PATH_SYNC_ENABLED, '0'],
            ]],
            'the initial setup not finished' => [[
                ['websites', self::MAIN, WizardStepSaver::XML_PATH_SETUP_COMPLETED, ''],
            ]],
            // Second's automations were picked from its own account: they
            // never become the automations for all websites.
            'websites on different Smaily accounts' => [[
                ['default', 0, Config::XML_PATH_SUBDOMAIN, 'shop'],
                ['default', 0, Config::XML_PATH_USERNAME, 'api-user'],
                ['default', 0, Config::XML_PATH_PASSWORD, 'encrypted'],
                ['websites', self::SECOND, Config::XML_PATH_SUBDOMAIN, 'other-shop'],
                ['websites', self::SECOND, Config::XML_PATH_USERNAME, 'other-user'],
                ['websites', self::SECOND, Config::XML_PATH_PASSWORD, 'encrypted'],
                ['websites', self::SECOND, Config::XML_PATH_WELCOME_ENABLED, '1'],
                ['websites', self::SECOND, Config::XML_PATH_WELCOME_WORKFLOW, '9'],
            ], true],
            'a store view on another Smaily account' => [[
                ['stores', self::SECOND, Config::XML_PATH_SUBDOMAIN, 'other-shop'],
                ['stores', self::SECOND, Config::XML_PATH_USERNAME, 'other-user'],
                ['stores', self::SECOND, Config::XML_PATH_PASSWORD, 'encrypted'],
            ], true],
        ];
    }

    /**
     * A signup on a website without a Smaily connection, while another
     * website has one, is in the Log as skipped, with the reason and the
     * contact a sync would have sent; nothing waits to be sent.
     */
    public function testASignupOnAWebsiteWithoutAConnectionIsRecordedAsSkipped(): void
    {
        $this->seedWebsite(self::MAIN, $this->encrypt('secret'));

        // Its Contacts settings never saved: contact sync would not be
        // active once connected, so nothing is recorded, as before.
        $this->observer()->execute($this->signup(self::SECOND, 'second@example.com'));
        self::assertSame([], $this->fetchAll(EventResource::TABLE_NAME));

        $this->seed('default', 0, [Config::XML_PATH_CONTACTS_CONFIRMED => '1']);
        $this->observer()->execute($this->signup(self::SECOND, 'second@example.com'));

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertCount(1, $rows);
        self::assertSame(EventType::CONTACT_SYNC, $rows[0]['event_type']);
        self::assertSame(self::SECOND, (int)$rows[0]['website_id']);
        self::assertSame('sent', $rows[0]['status'], 'Closed: the Log shows it as Skipped');
        self::assertSame(WebsiteConnections::SKIPPED_NOT_CONNECTED, $rows[0]['last_error']);
        self::assertSame(
            ['email' => 'second@example.com', 'is_unsubscribed' => 0],
            array_intersect_key(
                json_decode((string)$rows[0]['payload'], true)['contact'],
                ['email' => true, 'is_unsubscribed' => true]
            )
        );
        self::assertSame(['Website ' . self::SECOND], array_values(
            (new WebsiteConnections($this->config(), $this->storeManager()))->unconnectedWebsites()
        ));
    }

    /**
     * A store with a Smaily account for all websites: the settings for all
     * websites win. Second's own login of the same account and its own
     * workflow go; Main's own Contacts settings differ in nothing of who is
     * synced and go too; Second's own Contacts settings sync another mode,
     * so they stay until the merchant confirms on Settings > Contacts.
     * Second's storefront address stays its own.
     */
    public function testTheSettingsForAllWebsitesWinExceptContactsThatAskForConsent(): void
    {
        $this->seed('default', 0, [
            WizardStepSaver::XML_PATH_SETUP_COMPLETED => '1',
            Config::XML_PATH_SUBDOMAIN => 'shop',
            Config::XML_PATH_USERNAME => 'api-user',
            Config::XML_PATH_PASSWORD => $this->encrypt('secret'),
            Config::XML_PATH_SYNC_ENABLED => '1',
            Config::XML_PATH_SYNC_MODE => 'consent',
            Config::XML_PATH_CONTACTS_CONFIRMED => '1',
            Config::XML_PATH_WELCOME_ENABLED => '1',
            Config::XML_PATH_WELCOME_WORKFLOW => '9',
        ]);
        $this->seed('websites', self::MAIN, [
            Config::XML_PATH_SYNC_MODE => 'consent',
            Config::XML_PATH_SYNC_FIELDS => 'first_name',
        ]);
        // Second's own password is the same one, encrypted anew.
        $this->seed('websites', self::SECOND, [
            Config::XML_PATH_USERNAME => 'api-user',
            Config::XML_PATH_PASSWORD => $this->encrypt('secret'),
            Config::XML_PATH_STOREFRONT_URL => 'https://second.example.com',
            Config::XML_PATH_WELCOME_WORKFLOW => '5',
            Config::XML_PATH_SYNC_MODE => 'legitimate_interest',
        ]);
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => self::SECOND,
            'trigger_type' => Trigger::WELCOME,
            'language' => 'et',
            'account_key' => 'default',
            'workflow_id' => 5,
            'is_default_fallback' => 0,
        ]);

        $this->runPatch();

        self::assertSame([
            [self::SECOND, Config::XML_PATH_STOREFRONT_URL, 'https://second.example.com'],
            [self::SECOND, Config::XML_PATH_SYNC_MODE, 'legitimate_interest'],
        ], $this->websiteRows());
        // The welcome's per-language row: no store-wide row for the welcome,
        // and Second alone holds one, so it becomes the one for all websites.
        self::assertSame([[0, 'welcome', 'et', 5]], $this->mappingRows());
        self::assertSame('secret', $this->config()->getPassword(self::SECOND));
        self::assertSame(
            [
                ['connection', self::SECOND, [Config::XML_PATH_USERNAME, Config::XML_PATH_PASSWORD]],
                ['automations', self::SECOND, [Config::XML_PATH_WELCOME_WORKFLOW, MappingResource::TABLE_NAME]],
                ['subscribers', self::MAIN, [Config::XML_PATH_SYNC_MODE, Config::XML_PATH_SYNC_FIELDS]],
            ],
            array_map(
                static fn (array $line): array => [
                    $line[1]['section'],
                    $line[1]['website_id'],
                    $line[1]['paths'],
                ],
                $this->logged
            )
        );
        foreach ($this->logged as [, $context]) {
            self::assertStringNotContainsString('legitimate_interest', json_encode($context, JSON_THROW_ON_ERROR));
        }
    }

    /**
     * CON-470 review: the upgrade never deletes the only working API
     * password. With none saved for all websites, the one the websites agree
     * on moves there, as stored, first; a website whose own password was
     * changed at its scope keeps its whole Smaily account, and the log says
     * why without a value.
     *
     * @dataProvider passwords
     *
     * @param array<int, string> $websitePasswords website id => its own password
     * @param int[] $kept the websites that keep their own Smaily account
     */
    public function testAWebsitesSmailyAccountGivesWayOnlyWithTheSamePassword(
        ?string $storeWidePassword,
        array $websitePasswords,
        array $kept
    ): void {
        $this->seed('default', 0, [
            WizardStepSaver::XML_PATH_SETUP_COMPLETED => '1',
            Config::XML_PATH_SUBDOMAIN => 'shop',
            Config::XML_PATH_USERNAME => 'api-user',
        ]);
        if ($storeWidePassword !== null) {
            $this->seed('default', 0, [Config::XML_PATH_PASSWORD => $this->encrypt($storeWidePassword)]);
        }
        foreach ($websitePasswords as $websiteId => $password) {
            $this->seed('websites', $websiteId, [
                Config::XML_PATH_USERNAME => 'api-user',
                Config::XML_PATH_PASSWORD => $this->encrypt($password),
            ]);
        }

        $this->runPatch();

        self::assertSame($kept, array_values(array_unique(array_column($this->websiteRows(), 0))));
        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            self::assertSame(
                $websitePasswords[$websiteId] ?? $storeWidePassword ?? 'secret',
                $this->config()->getWebsitePassword($websiteId),
                'Website ' . $websiteId . ' still sends with its password'
            );
        }
        foreach ($this->logged as [, $context]) {
            $logged = json_encode($context, JSON_THROW_ON_ERROR);
            foreach (['secret', 'changed'] as $password) {
                self::assertStringNotContainsString($password, $logged);
            }
        }
        self::assertSame(
            $kept,
            array_column(array_filter(
                array_column($this->logged, 1),
                static fn (array $context): bool => ($context['reason'] ?? '') === 'password differs'
            ), 'website_id')
        );
    }

    /**
     * @return array<string, array{0: ?string, 1: array<int, string>, 2: int[]}>
     */
    public static function passwords(): array
    {
        return [
            'identical passwords' => ['secret', [self::MAIN => 'secret', self::SECOND => 'secret'], []],
            'a password changed only at a website' => ['secret', [self::SECOND => 'changed'], [self::SECOND]],
            'none for all websites, the websites agree' => [null, [self::MAIN => 'secret', self::SECOND => 'secret'], []],
            'none for all websites, the websites disagree' => [
                null,
                [self::MAIN => 'secret', self::SECOND => 'changed'],
                [self::MAIN, self::SECOND],
            ],
        ];
    }

    /**
     * CON-470 review: the default scope holding only a fragment of the
     * automations (here the abandoned-cart wait) does not take a website's
     * welcome automation away: the values the websites agree on move to the
     * settings for all websites first, mapping rows by automation; values
     * the websites disagree on stay each website's own.
     */
    public function testAWebsitesAutomationsKeepWorkingWhenTheDefaultScopeHoldsOnlyAFragment(): void
    {
        $this->seed('default', 0, [
            WizardStepSaver::XML_PATH_SETUP_COMPLETED => '1',
            Config::XML_PATH_SUBDOMAIN => 'shop',
            Config::XML_PATH_USERNAME => 'api-user',
            Config::XML_PATH_PASSWORD => $this->encrypt('secret'),
            Config::XML_PATH_ABANDONED_CUTOFF => '60',
        ]);
        $this->seed('websites', self::SECOND, [
            Config::XML_PATH_WELCOME_ENABLED => '1',
            Config::XML_PATH_WELCOME_WORKFLOW => '5',
            Config::XML_PATH_ABANDONED_CUTOFF => '30',
        ]);
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => self::SECOND,
            'trigger_type' => Trigger::WELCOME,
            'language' => 'et',
            'account_key' => 'default',
            'workflow_id' => 77,
            'is_default_fallback' => 0,
        ]);

        $this->runPatch();

        self::assertSame([], $this->websiteRows());
        self::assertSame([[0, 'welcome', 'et', 77]], $this->mappingRows());
        $config = $this->config();
        $router = $this->objectManager->create(Router::class, [
            'config' => $config,
            'overrides' => $this->objectManager->create(WebsiteOverrides::class, ['storeManager' => $this->storeManager()]),
        ]);
        foreach ([self::MAIN, self::SECOND] as $websiteId) {
            self::assertTrue($config->isWelcomeEnabled($websiteId), 'Website ' . $websiteId);
            self::assertSame(5, $router->resolve(Trigger::WELCOME, $websiteId, 'en', 'single')?->workflowId);
        }
        self::assertSame('60', (string)$this->scopeConfig()->getValue(Config::XML_PATH_ABANDONED_CUTOFF));

        // Two websites that disagree keep their own.
        $this->connection->delete('core_config_data', ['path = ?' => Config::XML_PATH_WELCOME_WORKFLOW]);
        $this->seed('websites', self::MAIN, [Config::XML_PATH_WELCOME_WORKFLOW => '5']);
        $this->seed('websites', self::SECOND, [Config::XML_PATH_WELCOME_WORKFLOW => '6']);
        $this->logged = [];

        $this->runPatch();

        self::assertSame([
            [self::MAIN, Config::XML_PATH_WELCOME_WORKFLOW, '5'],
            [self::SECOND, Config::XML_PATH_WELCOME_WORKFLOW, '6'],
        ], $this->websiteRows());
        self::assertSame(
            [[self::MAIN, 'websites disagree'], [self::SECOND, 'websites disagree']],
            array_map(
                static fn (array $line): array => [$line[1]['website_id'], $line[1]['reason'] ?? ''],
                $this->logged
            )
        );
    }

    /**
     * Every channel that syncs a contact records the contact as skipped on a
     * website without a Smaily connection: a customer account save, the
     * checkout opt-in (as a subscription) and a guest order under All
     * customers (legitimate interest), each with what it would have sent.
     */
    public function testEverySignupChannelOnAWebsiteWithoutAConnectionIsRecordedAsSkipped(): void
    {
        $this->seedWebsite(self::MAIN, $this->encrypt('secret'));
        $this->seed('default', 0, [
            Config::XML_PATH_CONTACTS_CONFIRMED => '1',
            Config::XML_PATH_SYNC_MODE => 'legitimate_interest',
            Config::XML_PATH_INCLUDE_GUESTS => '1',
            Config::XML_PATH_SYNC_FIELDS => '',
        ]);
        $config = $this->config();
        $dispatcher = $this->dispatcher();

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(5);
        $customer->method('getStoreId')->willReturn(self::SECOND);
        $customer->method('getWebsiteId')->willReturn(self::SECOND);
        $customer->method('getEmail')->willReturn('account@example.com');
        $subscriber = $this->createMock(Subscriber::class);
        $subscriber->method('loadByCustomer')->willReturnSelf();
        $subscriberFactory = $this->createMock(SubscriberFactory::class);
        $subscriberFactory->method('create')->willReturn($subscriber);
        (new CustomerSaveAfter(
            $config,
            new Mode($config),
            new ReconcileGuard(),
            $dispatcher,
            $subscriberFactory,
            $this->unconnectedSignup($dispatcher)
        ))->execute(new Observer(['event' => new Event(['customer_data_object' => $customer])]));

        $orderPlaced = function (string $email, bool $optedIn, bool $guest) use ($config, $dispatcher): void {
            $state = $this->createMock(StateManager::class);
            $state->method('rowForQuote')->willReturn(['status' => '', 'newsletter_optin' => $optedIn]);
            $order = $this->createMock(OrderInterface::class);
            $order->method('getQuoteId')->willReturn(7);
            $order->method('getStoreId')->willReturn(self::SECOND);
            $order->method('getCustomerEmail')->willReturn($email);
            $order->method('getCustomerIsGuest')->willReturn($guest);
            (new OrderPlaced(
                $config,
                new Mode($config),
                $dispatcher,
                $state,
                $this->createMock(OrderCollectionFactory::class),
                $this->storeManager(),
                $this->createMock(SubscriptionManagerInterface::class),
                new StorefrontSubscription(),
                $this->createMock(ReminderConsent::class),
                $this->unconnectedSignup($dispatcher)
            ))->execute(new Observer(['event' => new Event(['order' => $order])]));
        };
        $orderPlaced('optin@example.com', true, false);
        $orderPlaced('guest@example.com', false, true);

        $rows = $this->fetchAll(EventResource::TABLE_NAME);
        self::assertSame(
            [
                ['account@example.com', null],
                ['optin@example.com', 0],
                ['guest@example.com', null],
            ],
            array_map(static function (array $row): array {
                $contact = json_decode((string)$row['payload'], true)['contact'];

                return [$contact['email'], $contact['is_unsubscribed'] ?? null];
            }, $rows)
        );
        foreach ($rows as $row) {
            self::assertSame(EventType::CONTACT_SYNC, $row['event_type']);
            self::assertSame(self::SECOND, (int)$row['website_id']);
            self::assertSame('sent', $row['status']);
            self::assertSame(WebsiteConnections::SKIPPED_NOT_CONNECTED, $row['last_error']);
        }
    }

    /**
     * Website MAIN as an initial setup on 3.0.0-rc1 to rc12 left it: its
     * own Smaily account (mode b), Contacts settings with the mark that
     * they were saved, automations with a per-language row, and the mark
     * that the setup is finished.
     */
    private function seedWebsite(int $websiteId, string $encryptedPassword): void
    {
        $this->seed('websites', $websiteId, [
            WizardStepSaver::XML_PATH_SETUP_COMPLETED => '1',
            Config::XML_PATH_STOREFRONT_URL => 'https://shop.example.com',
            Config::XML_PATH_SUBDOMAIN => 'shop',
            Config::XML_PATH_USERNAME => 'api-user',
            Config::XML_PATH_PASSWORD => $encryptedPassword,
            Config::XML_PATH_MULTILINGUAL_MODE => 'b',
            Config::XML_PATH_SYNC_ENABLED => '1',
            Config::XML_PATH_SYNC_MODE => 'consent',
            Config::XML_PATH_CONTACTS_CONFIRMED => '1',
            Config::XML_PATH_WELCOME_ENABLED => '1',
            Config::XML_PATH_WELCOME_WORKFLOW => '42',
            Config::XML_PATH_ABANDONED_ENABLED => '0',
        ]);
        $this->connection->insert(MappingResource::TABLE_NAME, [
            'website_id' => $websiteId,
            'trigger_type' => Trigger::WELCOME,
            'language' => 'et',
            'account_key' => 'default',
            'workflow_id' => 77,
            'is_default_fallback' => 0,
        ]);
    }

    private function runPatch(): StoreLayout
    {
        $layout = $this->storeLayoutFor($this->storeManager());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logged[] = [$message, $context];
        });
        $overrides = $this->objectManager->create(WebsiteOverrides::class, ['storeManager' => $this->storeManager()]);
        (new ShareSingleWebsiteSettings(
            $layout,
            $overrides,
            new WebsiteContacts(
                $this->objectManager->get(ResourceConnection::class),
                $this->scopeConfig(),
                $overrides
            ),
            $this->objectManager->get(EncryptorInterface::class),
            $logger
        ))->apply();

        return $layout;
    }

    /**
     * The module's observer of a subscriber save, on the storefront, with
     * real config, queue and website connections.
     */
    private function observer(): SubscriberSaveAfter
    {
        $config = $this->config();
        $storeManager = $this->storeManager();
        $payloadBuilder = new SubscriberPayloadBuilder(
            $config,
            $storeManager,
            $this->createMock(LanguageResolver::class),
            $this->createMock(GroupRepositoryInterface::class)
        );
        $appState = $this->createMock(State::class);
        $appState->method('getAreaCode')->willReturn('frontend');

        $dispatcher = $this->dispatcher();

        return new SubscriberSaveAfter(
            $config,
            new Mode($config),
            new ReconcileGuard(),
            $dispatcher,
            $payloadBuilder,
            $this->createMock(CustomerRepositoryInterface::class),
            $appState,
            new StorefrontSubscription(),
            $this->unconnectedSignup($dispatcher)
        );
    }

    /**
     * The contact syncs and their Log rows, on the real queue.
     */
    private function dispatcher(): SyncDispatcher
    {
        return new SyncDispatcher(
            new SubscriberPayloadBuilder(
                $this->config(),
                $this->storeManager(),
                $this->createMock(LanguageResolver::class),
                $this->createMock(GroupRepositoryInterface::class)
            ),
            $this->createMock(LanguageResolver::class),
            $this->storeManager(),
            $this->objectManager->create(EventQueue::class),
            $this->objectManager->create(ContactEntity::class)
        );
    }

    private function unconnectedSignup(SyncDispatcher $dispatcher): UnconnectedSignup
    {
        return new UnconnectedSignup(
            $this->config(),
            new WebsiteConnections($this->config(), $this->storeManager()),
            $dispatcher
        );
    }

    /**
     * A guest's newsletter subscription on the website's store view, as its
     * save event carries it: Magento's own subscriber model, its status
     * changed from none to subscribed.
     */
    private function signup(int $storeId, string $email): Observer
    {
        /** @var Subscriber $subscriber */
        $subscriber = $this->objectManager->create(Subscriber::class, [
            'resource' => $this->createMock(SubscriberResource::class),
            'newsletterData' => $this->createMock(NewsletterHelper::class),
            'transportBuilder' => $this->createMock(TransportBuilder::class),
            'storeManager' => $this->storeManager(),
            'customerSession' => $this->createMock(CustomerSession::class),
            'customerRepository' => $this->createMock(CustomerRepositoryInterface::class),
            'customerAccountManagement' => $this->createMock(AccountManagementInterface::class),
            'inlineTranslation' => $this->createMock(StateInterface::class),
            'customerFactory' => $this->createMock(CustomerInterfaceFactory::class),
            'dataObjectHelper' => $this->createMock(DataObjectHelper::class),
            'subscriptionManager' => $this->createMock(SubscriptionManagerInterface::class),
        ]);
        $subscriber->setOrigData('subscriber_status', null);
        $subscriber->setData('store_id', $storeId);
        $subscriber->setData('subscriber_email', $email);
        $subscriber->setData('subscriber_status', Subscriber::STATUS_SUBSCRIBED);

        return new Observer(['event' => new Event(['subscriber' => $subscriber]), 'subscriber' => $subscriber]);
    }

    /**
     * Config read from core_config_data with Magento's scope fallback.
     */
    private function config(): Config
    {
        return $this->objectManager->create(Config::class, ['scopeConfig' => $this->scopeConfig()]);
    }

    private function scopeConfig(): DatabaseScopeConfig
    {
        return new DatabaseScopeConfig($this->connection, [self::MAIN => self::MAIN, self::SECOND => self::SECOND]);
    }

    /**
     * @return StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private function storeManager(): StoreManagerInterface
    {
        $websites = [];
        $stores = [];
        foreach ([self::MAIN, self::SECOND] as $id) {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($id);
            $store->method('getWebsiteId')->willReturn($id);
            $store->method('getName')->willReturn('Store ' . $id);
            $website = $this->createMock(Website::class);
            $website->method('getId')->willReturn($id);
            $website->method('getName')->willReturn('Website ' . $id);
            $website->method('getStoreIds')->willReturn([$id]);
            $website->method('getDefaultStore')->willReturn($store);
            $store->method('getWebsite')->willReturn($website);
            $websites[$id] = $website;
            $stores[$id] = $store;
        }
        $defaultStore = $this->createMock(StoreInterface::class);
        $defaultStore->method('getWebsiteId')->willReturn(self::MAIN);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getDefaultStoreView')->willReturn($defaultStore);
        $storeManager->method('getWebsites')->willReturn($websites);
        $storeManager->method('getWebsite')->willReturnCallback(
            static fn (int $websiteId) => $websites[$websiteId] ?? null
        );
        $storeManager->method('getStore')->willReturnCallback(
            static fn ($storeId) => $stores[(int)$storeId] ?? $stores[self::MAIN]
        );

        return $storeManager;
    }

    private function encrypt(string $value): string
    {
        /** @var EncryptorInterface $encryptor */
        $encryptor = $this->objectManager->get(EncryptorInterface::class);

        return $encryptor->encrypt($value);
    }

    /**
     * @return array<string, string> the default scope's rows, path => value, by path
     */
    private function defaultRows(): array
    {
        return $this->connection->fetchPairs(
            $this->connection->select()->from('core_config_data', ['path', 'value'])
                ->where('scope = ?', 'default')
                ->order('path')
        );
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: string}> the website-scope rows: website, path, value
     */
    private function websiteRows(): array
    {
        return array_map(
            static fn (array $row): array => [(int)$row['scope_id'], (string)$row['path'], (string)$row['value']],
            $this->connection->fetchAll(
                $this->connection->select()->from('core_config_data', ['scope_id', 'path', 'value'])
                    ->where('scope = ?', 'websites')
                    ->order(['scope_id', 'path'])
            )
        );
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: string, 3: int}> website, trigger, language, workflow
     */
    private function mappingRows(): array
    {
        return array_map(
            static fn (array $row): array => [
                (int)$row['website_id'],
                (string)$row['trigger_type'],
                (string)$row['language'],
                (int)$row['workflow_id'],
            ],
            $this->fetchAll(MappingResource::TABLE_NAME)
        );
    }

    /**
     * @return array<int, array{0: string, 1: int, 2: string}> event type, website, status
     */
    private function queueRows(): array
    {
        return array_map(
            static fn (array $row): array => [(string)$row['event_type'], (int)$row['website_id'], (string)$row['status']],
            $this->fetchAll(EventResource::TABLE_NAME)
        );
    }

    /**
     * @param array<string, string> $values path => value
     */
    private function seed(string $scope, int $scopeId, array $values): void
    {
        foreach ($values as $path => $value) {
            $this->connection->insert('core_config_data', [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $path,
                'value' => $value,
            ]);
        }
    }
}
