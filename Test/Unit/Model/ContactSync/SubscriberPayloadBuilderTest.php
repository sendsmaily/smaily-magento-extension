<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\ContactSync;

use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncFields;
use Smaily\Connect\Model\ContactSync\SubscriberPayloadBuilder;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

class SubscriberPayloadBuilderTest extends TestCase
{
    private StoreManagerInterface $storeManager;
    private LanguageResolver $languageResolver;
    private GroupRepositoryInterface $groupRepository;

    protected function setUp(): void
    {
        $group = $this->createMock(Group::class);
        $group->method('getName')->willReturn('Main Website Store');
        $website = $this->createMock(Website::class);
        $website->method('getName')->willReturn('Main Website');
        $store = $this->createMock(Store::class);
        $store->method('getName')->willReturn('Default Store View');
        $store->method('getGroup')->willReturn($group);
        $store->method('getWebsite')->willReturn($website);
        $store->method('getWebsiteId')->willReturn(1);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);

        $this->languageResolver = $this->createMock(LanguageResolver::class);
        $this->languageResolver->method('forStore')->willReturn('en');

        $customerGroup = $this->createMock(GroupInterface::class);
        $customerGroup->method('getCode')->willReturn('General');
        $this->groupRepository = $this->createMock(GroupRepositoryInterface::class);
        $this->groupRepository->method('getById')->willReturn($customerGroup);
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function genderProvider(): array
    {
        return ['male' => [1, 'Male'], 'female' => [2, 'Female']];
    }

    /**
     * @dataProvider genderProvider
     */
    public function testGenderShipsUnderTheCrossPlatformWireKey(int $magentoGender, string $expected): void
    {
        $payload = $this->builder()->build('Shopper@Example.com', 1, false, $this->customer($magentoGender));

        self::assertSame($expected, $payload['user_gender']);
        self::assertArrayNotHasKey('gender', $payload, 'The pre-canon key must not ride along too');
    }

    public function testGenderIsOmittedWhenTheCustomerNeverPickedOne(): void
    {
        $payload = $this->builder()->build('shopper@example.com', 1, false, $this->customer(0));

        self::assertArrayNotHasKey('user_gender', $payload);
    }

    public function testPhoneShipsUnderTheCrossPlatformWireKeyFromTheDefaultBillingAddress(): void
    {
        $customer = $this->customer(1, ['other' => '+372 999 00000', 0 => '+372 555 12345']);

        $payload = $this->builder()->build('shopper@example.com', 1, false, $customer);

        self::assertSame('+372 555 12345', $payload['user_phone']);
    }

    /**
     * @return array<string, array{0: array<int|string, string>}>
     */
    public static function phonelessProvider(): array
    {
        return [
            'no addresses at all' => [[]],
            'only a non-billing address' => [['other' => '+372 999 00000']],
            'billing address without a number' => [['  ']],
        ];
    }

    /**
     * Absent, never empty — an empty value would wipe what Smaily holds.
     *
     * @param array<int|string, string> $addresses
     * @dataProvider phonelessProvider
     */
    public function testPhoneIsOmittedWhenTheCustomerHasNone(array $addresses): void
    {
        $payload = $this->builder()->build('shopper@example.com', 1, false, $this->customer(1, $addresses));

        self::assertArrayNotHasKey('user_phone', $payload);
    }

    public function testOnlyTheMerchantsSelectedFieldsAreSent(): void
    {
        $payload = $this->builder([SyncFields::FIELD_PHONE])
            ->build('shopper@example.com', 1, false, $this->customer(1));

        self::assertSame('+372 555 12345', $payload['user_phone']);
        self::assertArrayNotHasKey('user_gender', $payload);
    }

    /**
     * The Settings/wizard checkbox list and this reader must offer the same
     * field ids — the id IS the wire key.
     */
    public function testTheSelectableOptionsAreExactlyTheFieldsTheReaderKnows(): void
    {
        $options = array_column((new SyncFields())->toOptionArray(), 'value');

        self::assertSame(SyncFields::SUPPORTED_FIELDS, $options);
    }

    /**
     * @param string[]|null $selection merchant's stored field selection
     */
    private function builder(?array $selection = null): SubscriberPayloadBuilder
    {
        $config = $this->createMock(Config::class);
        $config->method('getSyncFields')->willReturn($selection ?? SyncFields::SUPPORTED_FIELDS);

        return new SubscriberPayloadBuilder(
            $config,
            $this->storeManager,
            $this->languageResolver,
            $this->groupRepository
        );
    }

    /**
     * @param array<int|string, string> $addresses telephone per address; a
     *     string key marks an address that is NOT the default billing one.
     */
    private function customer(int $gender, array $addresses = ['+372 555 12345']): CustomerInterface
    {
        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getId')->willReturn(42);
        $customer->method('getFirstname')->willReturn('Kati');
        $customer->method('getLastname')->willReturn('Tamm');
        $customer->method('getGender')->willReturn($gender);
        $customer->method('getGroupId')->willReturn(1);

        $mocks = [];
        foreach ($addresses as $key => $telephone) {
            $address = $this->createMock(AddressInterface::class);
            $address->method('isDefaultBilling')->willReturn(!is_string($key));
            $address->method('getTelephone')->willReturn($telephone);
            $mocks[] = $address;
        }
        $customer->method('getAddresses')->willReturn($mocks);

        return $customer;
    }
}
