<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Backfill;

use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Backfill\EngineImportGuard;
use Smaily\Connect\Model\Backfill\ImportStarter;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;

/**
 * CON-156: the one import start the admin import endpoint and
 * smaily:backfill:start share — the connection check first (PRO-1969,
 * PRO-3742), then one installation-wide job for an engine import, or one
 * per website for the contacts import.
 */
class ImportStarterTest extends TestCase
{
    private JobManager&MockObject $jobManager;

    /** @var array<int, array{string, string, int}> */
    private array $started = [];

    protected function setUp(): void
    {
        $this->jobManager = $this->createMock(JobManager::class);
        $this->jobManager->method('startIfIdle')->willReturnCallback(
            function (string $jobType, string $target, int $websiteId): ?Job {
                $this->started[] = [$jobType, $target, $websiteId];

                // Website 2 already has an active import.
                return $websiteId === 2 ? null : $this->createMock(Job::class);
            }
        );
    }

    /**
     * @dataProvider engineTypes
     */
    public function testAnEngineImportIsRefusedWhileCampaignIntelligenceIsNotConnected(string $jobType): void
    {
        $result = $this->starter(false)->start($jobType, Job::TARGET_ENGINE);

        self::assertNotNull($result['refusal']);
        self::assertSame([], $result['jobs']);
        self::assertSame([], $this->started);
    }

    /**
     * @dataProvider engineTypes
     */
    public function testAnEngineImportStartsOneInstallationWideJobWhileConnected(string $jobType): void
    {
        $result = $this->starter(true)->start($jobType, Job::TARGET_ENGINE, 2);

        self::assertNull($result['refusal']);
        self::assertSame([Job::ENGINE_WEBSITE_ID], array_keys($result['jobs']));
        self::assertSame([[$jobType, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID]], $this->started);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function engineTypes(): array
    {
        return [
            'catalog' => [Job::TYPE_CATALOG],
            'customers' => [Job::TYPE_CUSTOMERS],
            'orders' => [Job::TYPE_ORDERS],
        ];
    }

    public function testTheContactsImportStartsForEveryWebsiteWhileNotConnected(): void
    {
        $result = $this->starter(false)->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY);

        self::assertNull($result['refusal']);
        self::assertSame([
            [Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1],
            [Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 2],
            [Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 3],
        ], $this->started);
        self::assertSame([1, 2, 3], array_keys($result['jobs']));
        self::assertInstanceOf(Job::class, $result['jobs'][1]);
        self::assertNull($result['jobs'][2], 'An already-active import is not started again');
        self::assertInstanceOf(Job::class, $result['jobs'][3]);
    }

    public function testTheContactsImportStartsForOneWebsiteWhenGiven(): void
    {
        $result = $this->starter(true)->start(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 3);

        self::assertSame([3], array_keys($result['jobs']));
        self::assertSame([[Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 3]], $this->started);
    }

    private function starter(bool $connected): ImportStarter
    {
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn($connected);

        $websites = [];
        foreach ([1, 2, 3] as $id) {
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getId')->willReturn($id);
            $websites[] = $website;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsites')->willReturn($websites);

        return new ImportStarter($this->jobManager, $storeManager, new EngineImportGuard($engineSettings));
    }
}
