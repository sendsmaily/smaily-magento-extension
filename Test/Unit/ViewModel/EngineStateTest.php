<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\ViewModel\EngineState;

class EngineStateTest extends TestCase
{
    /**
     * Magento's cookie helper is annotated @return bool but actually returns
     * the raw config value — "0" (truthy in JS) when restriction mode is
     * off. The tracker config must carry a real boolean or the tracker
     * would wait for a cookie notice the store never shows.
     *
     * @dataProvider cookieRestrictionProvider
     */
    public function testCookieRestrictionIsRealBoolean(mixed $helperValue, bool $expected): void
    {
        $cookieHelper = $this->createMock(CookieHelper::class);
        $cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn($helperValue);

        $config = json_decode($this->viewModel($cookieHelper, '1')->getTrackerConfigJson(), true);

        self::assertSame($expected, $config['cookieRestriction']);
    }

    /**
     * The cookie notice's cookie is a map of the website ids the shopper
     * accepted on; the tracker needs the current website's id, as a number,
     * to read it.
     */
    public function testTrackerConfigCarriesTheCurrentWebsiteId(): void
    {
        $config = json_decode(
            $this->viewModel($this->createMock(CookieHelper::class), '2')->getTrackerConfigJson(),
            true
        );

        self::assertSame(2, $config['websiteId']);
    }

    /**
     * PRO-3790: the recommendations script asks the store's own route and
     * reads consent as the tracker does.
     */
    public function testRecommendationsConfigNamesTheRouteAndTheConsentSources(): void
    {
        $cookieHelper = $this->createMock(CookieHelper::class);
        $cookieHelper->method('isCookieRestrictionModeEnabled')->willReturn('0');

        $config = json_decode($this->viewModel($cookieHelper, '2')->getRecommendationsConfigJson(), true);

        self::assertSame(
            ['url' => 'https://store.example/smaily/recommendations/', 'cookieRestriction' => false, 'websiteId' => 2],
            $config
        );
    }

    private function viewModel(CookieHelper $cookieHelper, string $websiteId): EngineState
    {
        $attributionManager = $this->createMock(AttributionManager::class);
        $attributionManager->method('getClientConfig')->willReturn([]);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route): string => 'https://store.example/' . $route . '/'
        );

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn($websiteId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        return new EngineState(
            $this->createMock(Settings::class),
            $attributionManager,
            $cookieHelper,
            $urlBuilder,
            new Json(),
            $storeManager
        );
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function cookieRestrictionProvider(): array
    {
        return [
            'restriction off (string zero)' => ['0', false],
            'restriction off (null)' => [null, false],
            'restriction on (string one)' => ['1', true],
        ];
    }
}
