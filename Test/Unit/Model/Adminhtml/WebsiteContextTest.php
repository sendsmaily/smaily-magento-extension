<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Adminhtml;

use Magento\Framework\App\RequestInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Adminhtml\WebsiteContext;

/**
 * The single seam every admin save/prefill path reads its target website
 * through (RFC_MULTI_WEBSITE.md §2): a `website` request param wins when it
 * names a real website, otherwise the installation's default website —
 * unchanged behaviour for a single-website install.
 */
class WebsiteContextTest extends TestCase
{
    /** @var StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var RequestInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $request;

    /** @var Website&\PHPUnit\Framework\MockObject\MockObject */
    private $websiteOne;

    /** @var Website&\PHPUnit\Framework\MockObject\MockObject */
    private $websiteTwo;

    protected function setUp(): void
    {
        $defaultStoreView = $this->createMock(StoreInterface::class);
        $defaultStoreView->method('getWebsiteId')->willReturn(1);

        $storeOne = $this->createMock(Store::class);
        $storeOne->method('getId')->willReturn(1);
        $storeTwo = $this->createMock(Store::class);
        $storeTwo->method('getId')->willReturn(3);

        $this->websiteOne = $this->createMock(Website::class);
        $this->websiteOne->method('getId')->willReturn(1);
        $this->websiteOne->method('getName')->willReturn('Main Website');
        $this->websiteOne->method('getDefaultStore')->willReturn($storeOne);

        $this->websiteTwo = $this->createMock(Website::class);
        $this->websiteTwo->method('getId')->willReturn(2);
        $this->websiteTwo->method('getName')->willReturn('Second Website');
        $this->websiteTwo->method('getDefaultStore')->willReturn($storeTwo);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getDefaultStoreView')->willReturn($defaultStoreView);
        $this->storeManager->method('getWebsites')->willReturn([1 => $this->websiteOne, 2 => $this->websiteTwo]);
        $this->storeManager->method('getWebsite')->willReturnCallback(
            fn (int $id) => match ($id) {
                1 => $this->websiteOne,
                2 => $this->websiteTwo,
                default => null,
            }
        );

        $this->request = $this->createMock(RequestInterface::class);
    }

    private function context(): WebsiteContext
    {
        return new WebsiteContext($this->storeManager, $this->request);
    }

    public function testGetWebsiteIdFallsBackToTheDefaultWebsiteWhenNoParamIsPresent(): void
    {
        $this->request->method('getParam')->with('website')->willReturn(null);

        $this->assertSame(1, $this->context()->getWebsiteId());
        $this->assertFalse($this->context()->isExplicit());
    }

    public function testGetWebsiteIdUsesTheRequestParamWhenItNamesARealWebsite(): void
    {
        $this->request->method('getParam')->with('website')->willReturn('2');

        $this->assertSame(2, $this->context()->getWebsiteId());
        $this->assertTrue($this->context()->isExplicit());
    }

    public function testGetWebsiteIdIgnoresAParamNamingANonExistentWebsite(): void
    {
        $this->request->method('getParam')->with('website')->willReturn('999');

        $this->assertSame(1, $this->context()->getWebsiteId());
        $this->assertFalse($this->context()->isExplicit());
    }

    public function testGetWebsiteIdIgnoresABlankParam(): void
    {
        $this->request->method('getParam')->with('website')->willReturn('');

        $this->assertSame(1, $this->context()->getWebsiteId());
        $this->assertFalse($this->context()->isExplicit());
    }

    public function testGetStoreIdReturnsTheResolvedWebsitesOwnDefaultStore(): void
    {
        $this->request->method('getParam')->with('website')->willReturn('2');

        $this->assertSame(3, $this->context()->getStoreId());
    }

    public function testHasMultipleWebsitesIsTrueWithTwoRealWebsites(): void
    {
        $this->request->method('getParam')->willReturn(null);

        $this->assertTrue($this->context()->hasMultipleWebsites());
    }

    public function testHasMultipleWebsitesIsFalseWithOnlyOneRealWebsite(): void
    {
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getWebsites')->willReturn([1 => $this->websiteOne]);
        $this->request->method('getParam')->willReturn(null);

        $this->assertFalse($this->context()->hasMultipleWebsites());
    }

    public function testGetWebsiteOptionsMapsIdToName(): void
    {
        $this->assertSame(
            [1 => 'Main Website', 2 => 'Second Website'],
            $this->context()->getWebsiteOptions()
        );
    }
}
