<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Cookie\Helper\Cookie as CookieHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\MarketingConsent;

/**
 * CON-181: the server counts marketing consent only on an explicit yes —
 * Magento's cookie restriction mode on in the store view and the cookie
 * notice accepted on this website — read through Magento's own cookie
 * helper, as the WooCommerce plugin's MarketingConsent::given() counts only
 * a stored yes (PRO-3849). The store's JavaScript consentOverride() is not
 * visible on the server.
 */
class MarketingConsentTest extends TestCase
{
    /**
     * @dataProvider cases
     */
    public function testConsentIsGivenOnlyOnAnExplicitYes(string $restriction, ?string $cookie, bool $expected): void
    {
        self::assertSame($expected, $this->consent($restriction, $cookie, 2)->isGiven());
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: bool}>
     */
    public static function cases(): array
    {
        return [
            'restriction mode on, notice accepted on this website' => ['1', '{"2":1}', true],
            'restriction mode on, accepted on this and another website' => ['1', '{"1":1,"2":1}', true],
            'restriction mode on, accepted on another website only' => ['1', '{"1":1}', false],
            'restriction mode on, this website not accepted' => ['1', '{"2":0}', false],
            'restriction mode on, a value that does not parse' => ['1', 'yes', false],
            'restriction mode on, no cookie' => ['1', null, false],
            'restriction mode off, even with the cookie' => ['0', '{"2":1}', false],
            'restriction mode off, no cookie' => ['0', null, false],
        ];
    }

    private function consent(string $restriction, ?string $cookie, int $websiteId): MarketingConsent
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getCookie')->willReturnCallback(
            static fn (string $name) => $name === CookieHelper::IS_USER_ALLOWED_SAVE_COOKIE ? $cookie : null
        );
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            static fn (string $path) => $path === CookieHelper::XML_PATH_COOKIE_RESTRICTION ? $restriction : null
        );
        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        $website = $this->createMock(Website::class);
        $website->method('getId')->willReturn($websiteId);

        return new MarketingConsent(new CookieHelper(
            $context,
            $this->createMock(StoreManagerInterface::class),
            ['current_store' => $this->createMock(Store::class), 'website' => $website]
        ));
    }
}
