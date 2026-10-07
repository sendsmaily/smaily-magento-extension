<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Cron;

use Magento\Framework\FlagManager;
use Magento\Newsletter\Model\ResourceModel\Subscriber as SubscriberResource;
use Magento\Newsletter\Model\Subscriber;
use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Cron\ContactReconcile;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\ContactSync\ReconcileGuard;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\AccountResolver;

/**
 * PRO-1457/RFC_MULTI_WEBSITE.md §6: the cron must poll every distinct Smaily
 * account a website resolves to (mode A's per-language accounts included),
 * not just the default store's account, and never poll the same underlying
 * account twice.
 */
class ContactReconcileTest extends TestCase
{
    /** @var Config&MockObject */
    private $config;

    /** @var Mode&MockObject */
    private $mode;

    /** @var AccountResolver&MockObject */
    private $accountResolver;

    /** @var SmailyClientProvider&MockObject */
    private $clientProvider;

    /** @var FlagManager&MockObject */
    private $flagManager;

    /** @var array<int, array{store: int, actions: string}> */
    private array $historyCalls = [];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Support/Stub/SubscriberFactory.php';

        $this->config = $this->createMock(Config::class);
        $this->mode = $this->createMock(Mode::class);
        $this->accountResolver = $this->createMock(AccountResolver::class);
        $this->clientProvider = $this->createMock(SmailyClientProvider::class);
        $this->flagManager = $this->createMock(FlagManager::class);
        $this->historyCalls = [];

        $this->mode->method('reconciles')->willReturn(true);
    }

    /**
     * A mode-A website with two distinct per-language accounts must poll
     * BOTH — the pre-fix code only ever reached the default store's account.
     */
    public function testPollsEveryDistinctPerLanguageAccount(): void
    {
        $this->config->method('isSyncEnabled')->willReturn(true);
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('getSubdomain')->willReturnMap([
            [1, 'demo-default'],
            [5, 'demo-et'],
            [6, 'demo-en'],
        ]);
        $this->config->method('getUsername')->willReturnMap([
            [1, 'user-default'],
            [5, 'user-et'],
            [6, 'user-en'],
        ]);

        $this->accountResolver->method('languageStoreIds')->with(1)->willReturn(['et' => 5, 'en' => 6]);

        $this->stubClientsWithEmptyHistory();

        $this->createCron()->execute();

        self::assertSame([1, 5, 6], array_column($this->historyCalls, 'store'), 'Default + both per-language accounts polled');
    }

    /**
     * Two account keys that resolve to the same underlying Smaily credentials
     * (e.g. a language with no per-language override) must be polled once,
     * not once per account key.
     */
    public function testDoesNotPollTheSameAccountTwice(): void
    {
        $this->config->method('isSyncEnabled')->willReturn(true);
        $this->config->method('isConnected')->willReturn(true);
        // Store 6 ('en') has no override and inherits the same credentials
        // as the website's default store (1).
        $this->config->method('getSubdomain')->willReturnMap([
            [1, 'demo-default'],
            [6, 'demo-default'],
        ]);
        $this->config->method('getUsername')->willReturnMap([
            [1, 'user-default'],
            [6, 'user-default'],
        ]);

        $this->accountResolver->method('languageStoreIds')->with(1)->willReturn(['en' => 6]);

        $this->stubClientsWithEmptyHistory();

        $this->createCron()->execute();

        self::assertSame([1], array_column($this->historyCalls, 'store'), 'The duplicate account is polled only once');
    }

    /**
     * The website's own default account must keep the pre-existing flag key
     * (no suffix) so an upgrade does not replay its whole history.
     */
    public function testDefaultAccountKeepsThePreExistingFlagKey(): void
    {
        $this->config->method('isSyncEnabled')->willReturn(true);
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('getSubdomain')->willReturn('demo');
        $this->config->method('getUsername')->willReturn('user');
        $this->accountResolver->method('languageStoreIds')->willReturn([]);

        $this->stubClientsWithEmptyHistory();

        $this->flagManager->expects(self::once())->method('getFlagData')
            ->with('smaily_connect_reconcile_seq_w1')
            ->willReturn(0);
        $this->flagManager->expects(self::once())->method('saveFlag')
            ->with('smaily_connect_reconcile_seq_w1', 0);

        $this->createCron()->execute();
    }

    /**
     * An additional per-language account gets its own suffixed flag key, so
     * its cursor never collides with the default account's.
     */
    public function testPerLanguageAccountGetsItsOwnSuffixedFlagKey(): void
    {
        $this->config->method('isSyncEnabled')->willReturn(true);
        $this->config->method('isConnected')->willReturn(true);
        $this->config->method('getSubdomain')->willReturnMap([
            [1, 'demo-default'],
            [5, 'demo-et'],
        ]);
        $this->config->method('getUsername')->willReturnMap([
            [1, 'user-default'],
            [5, 'user-et'],
        ]);
        $this->accountResolver->method('languageStoreIds')->willReturn(['et' => 5]);

        $this->stubClientsWithEmptyHistory();

        $flagKeys = [];
        $this->flagManager->method('saveFlag')->willReturnCallback(
            function (string $key) use (&$flagKeys): void {
                $flagKeys[] = $key;
            }
        );

        $this->createCron()->execute();

        self::assertSame(['smaily_connect_reconcile_seq_w1', 'smaily_connect_reconcile_seq_w1_et'], $flagKeys);
    }

    /**
     * An account whose credentials are not configured is skipped rather than
     * attempted (no client obtained, no cursor read/written for it).
     */
    public function testUnconfiguredAccountIsSkipped(): void
    {
        $this->config->method('isSyncEnabled')->willReturn(true);
        $this->config->method('isConnected')->willReturnMap([
            [1, true],
            [5, false],
        ]);
        $this->config->method('getSubdomain')->willReturn('demo-default');
        $this->config->method('getUsername')->willReturn('user-default');
        $this->accountResolver->method('languageStoreIds')->willReturn(['et' => 5]);

        $this->stubClientsWithEmptyHistory();

        $this->createCron()->execute();

        self::assertSame([1], array_column($this->historyCalls, 'store'));
    }

    private function stubClientsWithEmptyHistory(): void
    {
        $this->clientProvider->method('forStore')->willReturnCallback(
            function (int $storeId): SmailyClient {
                $client = $this->createMock(SmailyClient::class);
                $client->method('get')->willReturnCallback(
                    function (string $endpoint, array $query) use ($storeId): array {
                        $this->historyCalls[] = ['store' => $storeId, 'actions' => $query['actions'] ?? ''];

                        return [];
                    }
                );

                return $client;
            }
        );
    }

    private function createCron(): ContactReconcile
    {
        $defaultStore = $this->createMock(Store::class);
        $defaultStore->method('getId')->willReturn(1);

        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn(1);
        $website->method('getDefaultStore')->willReturn($defaultStore);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn([$website]);

        return new ContactReconcile(
            $storeManager,
            $this->config,
            $this->mode,
            $this->accountResolver,
            $this->clientProvider,
            $this->flagManager,
            new ReconcileGuard(),
            $this->createMock(SubscriberFactory::class),
            $this->createMock(SubscriberResource::class),
            $this->createMock(Logger::class)
        );
    }
}
