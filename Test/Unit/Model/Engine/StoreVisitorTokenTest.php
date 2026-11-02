<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\CookieSizeLimitReachedException;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\AttributionCookieWriter;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\AttributionShape;
use Smaily\Connect\Model\Engine\StoreVisitorToken;
use Smaily\Connect\Model\Logger\Logger;

/**
 * CON-260 (contract §7 "Store-created token at login", §5 format): the
 * token's format, and the visitor-token cookie it is set in.
 */
class StoreVisitorTokenTest extends TestCase
{
    /** @var array<string, array{value: string, metadata: array<string, mixed>}> */
    private array $set = [];

    public function testATokenIsVsAndExactly22LettersAndDigits(): void
    {
        $tokens = [];
        $characters = [];
        for ($i = 0; $i < 2000; $i++) {
            $token = StoreVisitorToken::generate();
            self::assertMatchesRegularExpression('/^vs_[A-Za-z0-9]{22}$/D', $token);
            self::assertSame(25, strlen($token));
            self::assertTrue(AttributionShape::isStoreVisitorToken($token));
            $tokens[$token] = true;
            foreach (str_split(substr($token, 3)) as $character) {
                $characters[$character] = true;
            }
        }

        self::assertCount(2000, $tokens, 'No two tokens alike');
        self::assertCount(62, $characters, 'Every letter and digit is drawn');
    }

    public function testIssueSetsTheTokenInTheVisitorCookieWithTheEngineNameAndLifetime(): void
    {
        $token = $this->issuer(['cookieVisitor' => 'tenant_uid', 'ttlVisitorDays' => 400])->issue();

        self::assertNotNull($token);
        self::assertTrue(AttributionShape::isStoreVisitorToken($token));
        self::assertSame(['tenant_uid'], array_keys($this->set));
        self::assertSame($token, $this->set['tenant_uid']['value']);
        $expected = [
            PublicCookieMetadata::KEY_DOMAIN => '.store.example',
            PublicCookieMetadata::KEY_PATH => '/',
            PublicCookieMetadata::KEY_SECURE => false,
            PublicCookieMetadata::KEY_HTTP_ONLY => false,
            PublicCookieMetadata::KEY_SAME_SITE => 'Lax',
            PublicCookieMetadata::KEY_DURATION => 400 * 86400,
        ];
        ksort($expected);
        self::assertSame($expected, $this->set['tenant_uid']['metadata']);
    }

    public function testATokenWhoseCookieCannotBeSetIsNotIssued(): void
    {
        self::assertNull($this->issuer(['cookieVisitor' => 'tenant_uid', 'ttlVisitorDays' => 400], true)->issue());
        self::assertSame([], $this->set);
    }

    public function testWithoutACookieNameNoTokenIsIssued(): void
    {
        self::assertNull($this->issuer(['cookieVisitor' => '', 'ttlVisitorDays' => 400])->issue());
        self::assertSame([], $this->set);
    }

    /**
     * @param array<string, string|int> $clientConfig
     */
    private function issuer(array $clientConfig, bool $failing = false): StoreVisitorToken
    {
        $attributionManager = $this->createMock(AttributionManager::class);
        $attributionManager->method('getClientConfig')->willReturn($clientConfig);
        $request = $this->createMock(RequestInterface::class);
        $request->method('isSecure')->willReturn(false);
        $cookieManager = $this->createMock(CookieManagerInterface::class);
        $cookieManager->method('setPublicCookie')->willReturnCallback(
            function (string $name, string $value, PublicCookieMetadata $metadata) use ($failing): void {
                if ($failing) {
                    throw new CookieSizeLimitReachedException(new Phrase('too many cookies'));
                }
                $array = $metadata->__toArray();
                ksort($array);
                $this->set[$name] = ['value' => $value, 'metadata' => $array];
            }
        );
        $metadataFactory = $this->createMock(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')
            ->willReturnCallback(static fn (): PublicCookieMetadata => new PublicCookieMetadata());
        $sessionConfig = $this->createMock(SessionConfig::class);
        $sessionConfig->method('getCookieDomain')->willReturn('.store.example');
        $sessionConfig->method('getCookiePath')->willReturn('/');

        return new StoreVisitorToken(
            $attributionManager,
            new AttributionCookieWriter($request, $cookieManager, $metadataFactory, $sessionConfig),
            $this->createMock(Logger::class)
        );
    }
}
