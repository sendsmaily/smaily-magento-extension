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
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Test\Unit\Support\EngineConnectionsStub;
use Smaily\Connect\ViewModel\EngineState;

class EngineStateTest extends TestCase
{
    use EngineConnectionsStub;

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

    /**
     * CON-211: the landing capture, and the tracker that runs it too, post
     * the click to the store route that sets the cookies.
     */
    public function testAttributionConfigNamesTheLandingRoute(): void
    {
        $viewModel = $this->viewModel($this->createMock(CookieHelper::class), '1');

        $attribution = json_decode($viewModel->getAttributionConfigJson(), true);
        $tracker = json_decode($viewModel->getTrackerConfigJson(), true);

        self::assertSame(
            ['cookieVisitor' => 'smaily_rec_uid', 'landUrl' => 'https://store.example/smaily/attribution/land/'],
            $attribution
        );
        self::assertSame($attribution, $tracker['attribution']);
    }

    /**
     * CON-237: separate stores — the scripts load by the gates of the
     * connection serving the store view, and not at all where none serves
     * it, whatever the other connections allow.
     */
    public function testTheGatesAreThoseOfTheConnectionOfTheStoreView(): void
    {
        $primary = $this->createMock(Settings::class);
        $primary->method('isConnected')->willReturn(true);
        $primary->method('isBrowseTrackingEnabled')->willReturn(true);
        $primary->method('isSendingAllowed')->willReturn(true);
        $latvia = $this->createMock(Settings::class);
        $latvia->method('isConnected')->willReturn(true);
        $latvia->method('isBrowseTrackingEnabled')->willReturn(false);
        $latvia->method('isSendingAllowed')->willReturn(false);
        $connections = fn (int $storeId) => $this->engineConnections([
            [$this->engineConnection(0, $primary, [1], [1], 1), null],
            [$this->engineConnection(2, $latvia, [2], [2], 2), null],
        ], $storeId);

        $atLatvia = $this->viewModel($this->createMock(CookieHelper::class), '2', $connections(2));
        self::assertTrue($atLatvia->isAttributionActive());
        self::assertFalse($atLatvia->isBrowseTrackingActive());
        self::assertFalse($atLatvia->isRecommendationsActive());

        $unserved = $this->viewModel($this->createMock(CookieHelper::class), '3', $connections(3));
        self::assertFalse($unserved->isAttributionActive());
        self::assertFalse($unserved->isBrowseTrackingActive());
        self::assertFalse($unserved->isRecommendationsActive());
    }

    private function viewModel(
        CookieHelper $cookieHelper,
        string $websiteId,
        ?Connections $connections = null
    ): EngineState {
        $attributionManager = $this->createMock(AttributionManager::class);
        $attributionManager->method('getClientConfig')->willReturn(['cookieVisitor' => 'smaily_rec_uid']);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            static fn (string $route): string => 'https://store.example/' . $route . '/'
        );

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getId')->willReturn($websiteId);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($website);

        return new EngineState(
            $connections ?? $this->oneEngineConnection($this->createMock(Settings::class)),
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
