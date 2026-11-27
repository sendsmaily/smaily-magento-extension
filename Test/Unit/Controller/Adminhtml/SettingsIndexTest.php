<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Controller\Adminhtml;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Controller\Adminhtml\Settings\Index as SettingsIndex;
use Smaily\Connect\Model\Adminhtml\SetupGuard;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;
use Smaily\Connect\Model\StoreLayout;

/**
 * CON-470: one store on one Smaily account shares its settings across its
 * websites, so Settings reads and saves the values for all websites, as
 * the initial setup does: the page marks its request store-wide, and its
 * calls then carry website 0. Any other store's Settings shows a website.
 */
class SettingsIndexTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('stores')]
    public function testSettingsIsStoreWideOnlyForOneStoreOnOneSmailyAccount(bool $sharesOneAccount): void
    {
        $title = $this->createMock(Title::class);
        $pageConfig = $this->createMock(PageConfig::class);
        $pageConfig->method('getTitle')->willReturn($title);
        $page = $this->createMock(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $resultFactory = $this->createMock(ResultFactory::class);
        $resultFactory->method('create')->with(ResultFactory::TYPE_PAGE)->willReturn($page);
        $context = $this->createMock(Context::class);
        $context->method('getResultFactory')->willReturn($resultFactory);
        $storeLayout = $this->createMock(StoreLayout::class);
        $storeLayout->method('sharesOneAccount')->willReturn($sharesOneAccount);
        $websiteContext = $this->createMock(WebsiteContext::class);
        $websiteContext->expects($sharesOneAccount ? self::once() : self::never())->method('useStoreWide');

        $controller = new SettingsIndex($context, $this->createMock(SetupGuard::class), $websiteContext, $storeLayout);

        self::assertSame($page, $controller->execute());
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function stores(): array
    {
        return [
            'one store on one Smaily account' => [true],
            'websites on their own (other accounts, per-language accounts, separate stores)' => [false],
        ];
    }
}
