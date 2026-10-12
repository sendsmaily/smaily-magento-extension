<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Notification\NotifierInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Dashboard\Index as DashboardIndex;
use Smaily\Connect\Controller\Adminhtml\Log\Index as LogIndex;
use Smaily\Connect\Controller\Adminhtml\Settings\Index as SettingsIndex;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\ModuleVersion;

/**
 * PRO-4012: Settings, Dashboard or Log opened for a website that is not set
 * up yet redirect to the initial setup of that website. Without the website
 * the initial setup opened on its chooser, which starts on the first
 * website, so the merchant saw the main website.
 */
class SetupRedirectTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string<Action>}>
     */
    public static function pages(): array
    {
        return [
            'Settings' => [SettingsIndex::class],
            'Dashboard' => [DashboardIndex::class],
            'Log' => [LogIndex::class],
        ];
    }

    /**
     * @param class-string<SettingsIndex|DashboardIndex|LogIndex> $controllerClass
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pages')]
    public function testAPageForAWebsiteNotSetUpRedirectsToTheSetupOfThatWebsite(string $controllerClass): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn(false);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->method('isExplicit')->willReturn(true);
        $websiteContext->method('getWebsiteId')->willReturn(2);
        $setupGuard = new SetupGuard(
            $scopeConfig,
            $this->createMock(WriterInterface::class),
            $this->createMock(TypeListInterface::class),
            $this->createMock(NotifierInterface::class),
            $this->createMock(ModuleVersion::class),
            $websiteContext
        );

        $redirect = $this->createMock(Redirect::class);
        $redirect->expects(self::once())->method('setPath')
            ->with('smaily_connect/wizard', ['_query' => ['website' => 2]])
            ->willReturnSelf();
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_REDIRECT)->willReturn($redirect);
        $context = $this->createMock(Context::class);
        $context->method('getResultFactory')->willReturn($resultFactory);

        self::assertSame($redirect, (new $controllerClass($context, $setupGuard))->execute());
    }
}
