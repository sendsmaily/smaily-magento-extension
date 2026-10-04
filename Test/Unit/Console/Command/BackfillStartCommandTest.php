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
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PRO-1969: `smaily:backfill:start catalog` does not start an import while
 * Campaign Intelligence is not connected, and says why.
 */
class BackfillStartCommandTest extends TestCase
{
    private JobManager&MockObject $jobManager;

    protected function setUp(): void
    {
        $this->jobManager = $this->createMock(JobManager::class);
    }

    public function testACatalogImportDoesNotStartWhileCampaignIntelligenceIsNotConnected(): void
    {
        $this->jobManager->expects(self::never())->method('start');

        $tester = $this->startCatalogImport(false);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(
            'Campaign Intelligence is not connected, so there is nowhere to send the catalog.',
            $tester->getDisplay()
        );
    }

    public function testACatalogImportStartsWhileCampaignIntelligenceIsConnected(): void
    {
        $job = $this->createMock(Job::class);
        $job->method('getId')->willReturn(7);
        $this->jobManager->expects(self::once())->method('start')
            ->with(Job::TYPE_CATALOG, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
            ->willReturn($job);

        $tester = $this->startCatalogImport(true);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Started catalog backfill #7', $tester->getDisplay());
    }

    private function startCatalogImport(bool $connected): CommandTester
    {
        $engineSettings = $this->createMock(EngineSettings::class);
        $engineSettings->method('isConnected')->willReturn($connected);
        $tester = new CommandTester(new BackfillStartCommand(
            $this->jobManager,
            $this->createMock(StoreManagerInterface::class),
            $engineSettings
        ));
        $tester->execute(['type' => Job::TYPE_CATALOG]);

        return $tester;
    }
}
