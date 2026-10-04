<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Console\Command\BackfillStartCommand;
use Smaily\Connect\Model\Backfill\EngineImportGuard;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PRO-1969, PRO-3742: `smaily:backfill:start catalog|customers|orders` does
 * not start an import while Campaign Intelligence is not connected, and says
 * why; `smaily:backfill:start contacts` goes to Smaily and starts.
 */
class BackfillStartCommandTest extends TestCase
{
    private JobManager&MockObject $jobManager;

    protected function setUp(): void
    {
        $this->jobManager = $this->createMock(JobManager::class);
    }

    /**
     * @dataProvider engineImports
     */
    public function testAnEngineImportDoesNotStartWhileCampaignIntelligenceIsNotConnected(
        string $jobType,
        string $message
    ): void {
        $this->jobManager->expects(self::never())->method('startIfIdle');

        $tester = $this->startImport($jobType, false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    /**
     * @dataProvider engineImports
     */
    public function testAnEngineImportStartsWhileCampaignIntelligenceIsConnected(string $jobType): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(7);
        $this->jobManager->expects(self::once())->method('startIfIdle')
            ->with($jobType, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
            ->willReturn($job);

        $tester = $this->startImport($jobType, true);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Started ' . $jobType . ' backfill #7', $tester->getDisplay());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function engineImports(): array
    {
        return [
            'catalog' => [
                Job::TYPE_CATALOG,
                'Campaign Intelligence is not connected, so there is nowhere to send the catalog.',
            ],
            'customers' => [
                Job::TYPE_CUSTOMERS,
                'Campaign Intelligence is not connected, so there is nowhere to send the customer data.',
            ],
            'orders' => [
                Job::TYPE_ORDERS,
                'Campaign Intelligence is not connected, so there is nowhere to send the order data.',
            ],
        ];
    }

    public function testTheContactsImportStartsWhileCampaignIntelligenceIsNotConnected(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(8);
        $this->jobManager->expects(self::once())->method('startIfIdle')
            ->with(Job::TYPE_CONTACTS, Job::TARGET_SMAILY, 1)
            ->willReturn($job);

        $tester = $this->startImport(Job::TYPE_CONTACTS, false, ['--website' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Started contacts backfill #8', $tester->getDisplay());
    }

    public function testAnImportAlreadyActiveIsNotStartedAgainAndSaysSo(): void
    {
        $message = 'A "catalog" import to engine is already running for website 0.';
        $this->jobManager->expects(self::once())->method('startIfIdle')->willReturn(null);
        $this->jobManager->method('alreadyActiveMessage')
            ->with(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
            ->willReturn($message);

        $tester = $this->startImport(Job::TYPE_CATALOG, true);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
    }

    /**
     * @param array<string, string> $options
     */
    private function startImport(string $jobType, bool $connected, array $options = []): CommandTester
    {
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn($connected);
        $tester = new CommandTester(new BackfillStartCommand(
            $this->jobManager,
            $this->createMock(StoreManagerInterface::class),
            new EngineImportGuard($engineSettings)
        ));
        $tester->execute(['type' => $jobType] + $options);

        return $tester;
    }
}
