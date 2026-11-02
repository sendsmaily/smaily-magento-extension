<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Engine\Websites;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * CON-227: a customer can keep the id of a store view that no longer exists;
 * Magento throws when asked for that store's locale, and that one customer
 * stopped the whole customers import.
 *
 * CON-253 (contract v1.21.0 §4): each row names the website of its customer
 * account, so the engine keeps the customer id and language of each website
 * for a shopper with an account on several websites.
 */
class CustomerPayloadBuilderTest extends TestCase
{
    /** @var LanguageResolver&MockObject */
    private LanguageResolver $languageResolver;

    /** @var Logger&MockObject */
    private Logger $logger;

    private CustomerPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->languageResolver = $this->createMock(LanguageResolver::class);
        $this->logger = $this->createMock(Logger::class);
        $this->builder = new CustomerPayloadBuilder($this->languageResolver, $this->websites(), $this->logger);
    }

    public function testACustomerOfAStoreViewThatNoLongerExistsGoesWithoutALanguage(): void
    {
        $this->languageResolver->method('forStore')->with(99)->willThrowException(new NoSuchEntityException(
            __("The store that was requested wasn't found. Verify the store and try again.")
        ));
        $this->logger->expects(self::once())->method('error')->with(
            'Customer sent without a language: its store view no longer exists',
            ['customer_id' => 7, 'store_id' => 99]
        );

        $item = $this->builder->build($this->customer(7, 99, 0));

        self::assertSame(['email' => 'c7@example.com', 'external_id' => '7'], $item);
    }

    public function testACustomerOfAStoreViewThatExistsGoesWithItsLanguage(): void
    {
        $this->languageResolver->method('forStore')->with(1)->willReturn('et');
        $this->logger->expects(self::never())->method('error');

        $item = $this->builder->build($this->customer(7, 1));

        self::assertSame('et', $item['language']);
    }

    public function testTwoAccountsOfOneEmailEachNameTheirOwnWebsite(): void
    {
        $this->languageResolver->method('forStore')->willReturnMap([[1, 'et'], [4, 'lv']]);

        $onFirst = $this->builder->build($this->customer(7, 1, 1, 'shopper@example.com'));
        $onSecond = $this->builder->build($this->customer(19, 4, 2, 'shopper@example.com'));

        self::assertSame(
            ['email' => 'shopper@example.com', 'external_id' => '7', 'language' => 'et', 'website' => 'base'],
            $onFirst
        );
        self::assertSame(
            ['email' => 'shopper@example.com', 'external_id' => '19', 'language' => 'lv', 'website' => 'lv'],
            $onSecond
        );
    }

    public function testAnAccountOfAWebsiteThatNoLongerExistsGoesWithoutAWebsite(): void
    {
        $this->languageResolver->method('forStore')->willReturn('et');

        $item = $this->builder->build($this->customer(7, 1, 9));

        self::assertArrayNotHasKey('website', $item);
    }

    /**
     * Websites 1 (`base`) and 2 (`lv`); any other id is not found.
     */
    private function websites(): Websites
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getWebsite')->willReturnCallback(function (int $id): WebsiteInterface {
            $code = [1 => 'base', 2 => 'lv'][$id] ?? null;
            if ($code === null) {
                throw new NoSuchEntityException(__('The website with id %1 that was requested wasn\'t found.', $id));
            }
            $website = $this->createMock(WebsiteInterface::class);
            $website->method('getCode')->willReturn($code);

            return $website;
        });

        return new Websites($storeManager, $this->createMock(ScopeConfigInterface::class));
    }

    private function customer(
        int $id,
        int $storeId,
        int $websiteId = 1,
        string $email = ''
    ): CustomerInterface&MockObject {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getEmail')->willReturn($email !== '' ? $email : 'c' . $id . '@example.com');
        $customer->method('getStoreId')->willReturn($storeId);
        $customer->method('getWebsiteId')->willReturn($websiteId);
        $customer->method('getAddresses')->willReturn([]);

        return $customer;
    }
}
