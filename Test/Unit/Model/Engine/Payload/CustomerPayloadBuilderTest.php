<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\CustomerPayloadBuilder;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * CON-227: a customer can keep the id of a store view that no longer exists;
 * Magento throws when asked for that store's locale, and that one customer
 * stopped the whole customers import.
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
        $this->builder = new CustomerPayloadBuilder($this->languageResolver, $this->logger);
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

        $item = $this->builder->build($this->customer(7, 99));

        self::assertSame(['email' => 'c7@example.com', 'external_id' => '7'], $item);
    }

    public function testACustomerOfAStoreViewThatExistsGoesWithItsLanguage(): void
    {
        $this->languageResolver->method('forStore')->with(1)->willReturn('et');
        $this->logger->expects(self::never())->method('error');

        $item = $this->builder->build($this->customer(7, 1));

        self::assertSame('et', $item['language']);
    }

    private function customer(int $id, int $storeId): CustomerInterface&MockObject
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn($id);
        $customer->method('getEmail')->willReturn('c' . $id . '@example.com');
        $customer->method('getStoreId')->willReturn($storeId);
        $customer->method('getAddresses')->willReturn([]);

        return $customer;
    }
}
