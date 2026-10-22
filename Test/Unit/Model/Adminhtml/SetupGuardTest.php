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
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Notification\NotifierInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\Adminhtml\WizardStepSaver;
use Smaily\Connect\Model\ModuleVersion;

/**
 * The setup-completed gate (CON-179): one mark for the store, and an install
 * whose website finished the setup before the update counts as set up.
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

    /**
     * CON-179: the setup is one mark for the store, saved at the default
     * scope.
     */
    public function testTheStoreIsSetUpWithTheStoresMark(): void
    {
        $this->websiteContext->method('getWebsiteOptions')->willReturn([1 => 'Main', 2 => 'Second']);
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT): bool =>
                $path === WizardStepSaver::XML_PATH_SETUP_COMPLETED
                && $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        $this->assertTrue($this->guard->isSetupCompleted());
    }

    /**
     * CON-179: a setup finished before the update saved the mark for one
     * website; the store counts as set up, so it is not sent back into the
     * setup, and nothing is rewritten.
     */
    public function testAStoreWhoseWebsiteFinishedTheSetupBeforeTheUpdateIsSetUp(): void
    {
        $this->websiteContext->method('getWebsiteOptions')->willReturn([1 => 'Main', 2 => 'Second']);
        $this->scopeConfig->method('isSetFlag')->willReturnCallback(
            static fn (string $path, string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $id = null): bool =>
                $scope === ScopeInterface::SCOPE_WEBSITE && $id === 2
        );

        $this->assertTrue($this->guard->isSetupCompleted());
    }

    public function testAStoreWithoutAnyMarkIsNotSetUp(): void
    {
        $this->websiteContext->method('getWebsiteOptions')->willReturn([1 => 'Main', 2 => 'Second']);
        $this->scopeConfig->method('isSetFlag')->willReturn(false);

        $this->assertFalse($this->guard->isSetupCompleted());
    }

    public function testAStoreSetUpGetsNoRedirect(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->expects($this->never())->method('create');

        $this->assertNull($this->guard->redirectIfNotSetUp($resultFactory));
    }

    /**
     * CON-179: the admin sends the merchant to the store's setup, never to
     * the setup of one website.
     */
    public function testAStoreNotSetUpRedirectsToTheSetupWithoutAWebsite(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(false);
        $this->websiteContext->method('getWebsiteOptions')->willReturn([1 => 'Main', 2 => 'Second']);
        $this->websiteContext->method('isExplicit')->willReturn(true);
        $this->websiteContext->method('getWebsiteId')->willReturn(2);
        $redirect = $this->createMock(Redirect::class);
        $redirect->expects($this->once())->method('setPath')
            ->with('smaily_connect/wizard')
            ->willReturnSelf();
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_REDIRECT)->willReturn($redirect);

        $this->assertSame($redirect, $this->guard->redirectIfNotSetUp($resultFactory));
    }
}
