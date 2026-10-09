<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\Escaper;
use Magento\Framework\Notification\MessageInterface;
use Magento\Framework\UrlInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\CustomersImportNotice;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;

/**
 * PRO-4007: a store connected to Campaign Intelligence whose customers
 * import has never completed is asked to start it, with a link to the
 * Intelligence tab where it starts; never while the store is not
 * connected, once an import has completed, or while one is queued or
 * running.
 */
class CustomersImportNoticeTest extends TestCase
{
    /** @var EngineSettings&MockObject */
    private $engineSettings;

    /** @var JobManager&MockObject */
    private $jobManager;

    /** @var UrlInterface&MockObject */
    private $urlBuilder;

    private CustomersImportNotice $notice;

    protected function setUp(): void
    {
        $this->engineSettings = $this->createMock(EngineSettings::class);
        $this->jobManager = $this->createMock(JobManager::class);
        $this->urlBuilder = $this->createMock(UrlInterface::class);
        $escaper = $this->createMock(Escaper::class);
        $escaper->method('escapeUrl')->willReturnArgument(0);

        $this->notice = new CustomersImportNotice(
            $this->engineSettings,
            $this->jobManager,
            $this->urlBuilder,
            $escaper
        );
    }

    public function testShowsWhileConnectedAndNoCustomersImportHasEverCompleted(): void
    {
        $this->engineSettings->method('isConnected')->willReturn(true);
        $this->jobManager->method('hasCompleted')
            ->with(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE)
            ->willReturn(false);
        $this->jobManager->method('findActive')
            ->with(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
            ->willReturn(null);

        self::assertTrue($this->notice->isDisplayed());
    }

    public function testHiddenOnceACustomersImportHasCompleted(): void
    {
        $this->engineSettings->method('isConnected')->willReturn(true);
        $this->jobManager->method('hasCompleted')
            ->with(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE)
            ->willReturn(true);
        $this->jobManager->method('findActive')->willReturn(null);

        self::assertFalse($this->notice->isDisplayed());
    }

    public function testHiddenWhileCampaignIntelligenceIsNotConnected(): void
    {
        $this->engineSettings->method('isConnected')->willReturn(false);
        $this->jobManager->method('hasCompleted')->willReturn(false);
        $this->jobManager->method('findActive')->willReturn(null);

        self::assertFalse($this->notice->isDisplayed());
    }

    public function testHiddenWhileACustomersImportIsQueuedOrRunning(): void
    {
        $this->engineSettings->method('isConnected')->willReturn(true);
        $this->jobManager->method('hasCompleted')->willReturn(false);
        $this->jobManager->method('findActive')
            ->with(Job::TYPE_CUSTOMERS, Job::TARGET_ENGINE, Job::ENGINE_WEBSITE_ID)
            ->willReturn($this->createMock(Job::class));

        self::assertFalse($this->notice->isDisplayed());
    }

    public function testTextLinksToTheIntelligenceTabWhereTheImportStarts(): void
    {
        $this->urlBuilder->method('getUrl')
            ->with('smaily_connect/settings', ['_query' => ['tab' => 'intelligence']])
            ->willReturn('https://shop.test/admin/smaily_connect/settings/?tab=intelligence');

        $text = $this->notice->getText();

        self::assertStringContainsString(
            '<a href="https://shop.test/admin/smaily_connect/settings/?tab=intelligence">Start the customers import</a>',
            $text
        );
        self::assertStringContainsString('press Start import, or Run again, on the Customers card', $text);
        self::assertSame(MessageInterface::SEVERITY_MAJOR, $this->notice->getSeverity());
    }
}
