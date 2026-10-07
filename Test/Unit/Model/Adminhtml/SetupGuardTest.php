<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\ModuleVersion;

/**
 * The setup-completed gate reads at the currently targeted website's scope
 * (RFC_MULTI_WEBSITE.md §2) so it stays in step with the wizard's own
 * website-chooser step and the Settings page's website selector.
 */
class SetupGuardTest extends TestCase
{
    /** @var ScopeConfigInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeConfig;

    /** @var WebsiteContext&\PHPUnit\Framework\MockObject\MockObject */
    private $websiteContext;

    private SetupGuard $guard;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->websiteContext = $this->createMock(WebsiteContext::class);

        $this->guard = new SetupGuard(
            $this->scopeConfig,
            $this->createMock(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(NotifierInterface::class),
            $this->createMock(ModuleVersion::class),
            $this->websiteContext
        );
    }

    public function testIsSetupCompletedReadsAtTheTargetedWebsitesScope(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(WizardStepSaver::XML_PATH_SETUP_COMPLETED, ScopeInterface::SCOPE_WEBSITE, 2)
            ->willReturn(true);

        $this->assertTrue($this->guard->isSetupCompleted());
    }

    public function testIsSetupCompletedReflectsAFalseFlagForTheTargetedWebsite(): void
    {
        $this->websiteContext->method('getWebsiteId')->willReturn(3);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $this->assertFalse($this->guard->isSetupCompleted());
    }
}
