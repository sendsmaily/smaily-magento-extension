<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Multilingual;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Multilingual\AccountResolver;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * The binding unit is website x language (RFC_MULTI_WEBSITE.md §2): two
 * websites sharing a language must never resolve to each other's store views
 * or accounts.
 */
class AccountResolverTest extends TestCase
{
    private const WEBSITE_ONE = 1;

    private const WEBSITE_TWO = 2;

    /** @var StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var LanguageResolver&\PHPUnit\Framework\MockObject\MockObject */
    private $languageResolver;

    private AccountResolver $resolver;

    protected function setUp(): void
    {
        // Website 1: store 1 (en, default), store 2 (et).
        // Website 2: store 3 (en, default) — a distinct account key from
        // website 1's 'en' even though the language code is identical.
        $storeOne = $this->createMock(Store::class);
        $storeOne->method('getId')->willReturn(1);
        $storeThree = $this->createMock(Store::class);
        $storeThree->method('getId')->willReturn(3);

        $websiteOne = $this->createMock(Website::class);
        $websiteOne->method('getStoreIds')->willReturn([1, 2]);
        $websiteOne->method('getDefaultStore')->willReturn($storeOne);

        $websiteTwo = $this->createMock(Website::class);
        $websiteTwo->method('getStoreIds')->willReturn([3]);
        $websiteTwo->method('getDefaultStore')->willReturn($storeThree);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getWebsite')->willReturnCallback(
            fn (int $websiteId) => match ($websiteId) {
                self::WEBSITE_ONE => $websiteOne,
                self::WEBSITE_TWO => $websiteTwo,
                default => throw new \RuntimeException('Unexpected website id'),
            }
        );

        $languages = [1 => 'en', 2 => 'et', 3 => 'en'];
        $this->languageResolver = $this->createMock(LanguageResolver::class);
        $this->languageResolver->method('forStore')->willReturnCallback(
            static fn (int $storeId): string => $languages[$storeId]
        );

        $this->resolver = new AccountResolver($this->storeManager, $this->languageResolver);
    }

    public function testDetectedLanguagesAreScopedToTheWebsiteDefaultFirst(): void
    {
        self::assertSame(['en', 'et'], $this->resolver->detectedLanguages(self::WEBSITE_ONE));
        self::assertSame(['en'], $this->resolver->detectedLanguages(self::WEBSITE_TWO));
    }

    public function testStoreIdsForAccountKeyDoNotBleedAcrossWebsites(): void
    {
        self::assertSame([1], $this->resolver->storeIdsForAccountKey('en', self::WEBSITE_ONE));
        self::assertSame([3], $this->resolver->storeIdsForAccountKey('en', self::WEBSITE_TWO));
        self::assertSame([2], $this->resolver->storeIdsForAccountKey('et', self::WEBSITE_ONE));
        self::assertSame([], $this->resolver->storeIdsForAccountKey('et', self::WEBSITE_TWO));
    }

    public function testStoreIdForAccountKeyResolvesWithinTheGivenWebsiteOnly(): void
    {
        self::assertSame(1, $this->resolver->storeIdForAccountKey('en', self::WEBSITE_ONE));
        self::assertSame(3, $this->resolver->storeIdForAccountKey('en', self::WEBSITE_TWO));
    }

    /**
     * @dataProvider defaultAccountKeyProvider
     */
    public function testStoreIdForAccountKeyIsNullForTheDefaultAccount(string $accountKey): void
    {
        self::assertNull($this->resolver->storeIdForAccountKey($accountKey, self::WEBSITE_ONE));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function defaultAccountKeyProvider(): array
    {
        return [
            'empty key' => [''],
            'default key' => ['default'],
        ];
    }

    public function testAWebsiteThatIsNotARealWebsiteModelResolvesEmpty(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturn($this->createMock(WebsiteInterface::class));
        $resolver = new AccountResolver($storeManager, $this->languageResolver);

        self::assertSame([], $resolver->detectedLanguages(self::WEBSITE_ONE));
        self::assertSame([], $resolver->storeIdsForAccountKey('en', self::WEBSITE_ONE));
    }

    public function testLanguageStoreIdsMapsEveryDetectedLanguageToItsRepresentativeStore(): void
    {
        self::assertSame(
            ['en' => 1, 'et' => 2],
            $this->resolver->languageStoreIds(self::WEBSITE_ONE)
        );
    }

    public function testAStaleWebsiteIdResolvesEmptyInsteadOfThrowing(): void
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willThrowException(
            new NoSuchEntityException(__('The website with id %1 that was requested wasn\'t found.', 99))
        );
        $resolver = new AccountResolver($storeManager, $this->languageResolver);

        self::assertSame([], $resolver->detectedLanguages(99));
        self::assertSame([], $resolver->storeIdsForAccountKey('en', 99));
        self::assertNull($resolver->storeIdForAccountKey('en', 99));
    }
}
