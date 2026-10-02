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

        $attributionManager = $this->createMock(AttributionManager::class);
        $attributionManager->method('getClientConfig')->willReturn([]);

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://store.example/smaily/relay/');

        $viewModel = new EngineState(
            $this->createMock(Settings::class),
            $attributionManager,
            $cookieHelper,
            $urlBuilder,
            new Json()
        );

        $config = json_decode($viewModel->getTrackerConfigJson(), true);

        self::assertSame($expected, $config['cookieRestriction']);
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
