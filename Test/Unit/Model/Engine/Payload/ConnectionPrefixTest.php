<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Payload\ConnectionPrefix;

/**
 * CON-359 (contract v1.33.0): the connection prefix goes in front of the
 * keys the contract lists — §3 `sku` and `tags.product_id`, §5
 * `external_order_id` and the lines' `sku`, §6 `sku` — and nowhere else;
 * without a prefix every item goes as it was queued. Items shaped as the
 * extension queues them (contract §3–§6 fields).
 */
class ConnectionPrefixTest extends TestCase
{
    public function testACatalogRowCarriesThePrefixOnItsSkuAndParentProductIdOnly(): void
    {
        $row = $this->catalogRow();
        $expected = $row;
        $expected['sku'] = 'c2-MJ01-XS-Blue';
        $expected['tags']['product_id'] = 'c2-1';

        self::assertSame($expected, ConnectionPrefix::item(Client::DOMAIN_CATALOG, $row, 'c2-'));
    }

    public function testATombstoneWithoutTagsCarriesThePrefixOnItsSku(): void
    {
        $tombstone = ['event_id' => 'f1c2d3e4-5a6b-4c7d-8e9f-0a1b2c3d4e5f', 'sku' => 'mag-5', 'in_stock' => false];

        self::assertSame(
            ['event_id' => 'f1c2d3e4-5a6b-4c7d-8e9f-0a1b2c3d4e5f', 'sku' => 'c9999-mag-5', 'in_stock' => false],
            ConnectionPrefix::item(Client::DOMAIN_CATALOG, $tombstone, 'c9999-')
        );
    }

    public function testAnOrderCarriesThePrefixOnItsIdAndEveryLinesSku(): void
    {
        $order = $this->order();
        $expected = $order;
        $expected['external_order_id'] = 'c2-000000123';
        $expected['items'][0]['sku'] = 'c2-MJ01-XS-Blue';
        $expected['items'][1]['sku'] = 'c2-24-WG085';

        self::assertSame($expected, ConnectionPrefix::item(Client::DOMAIN_ORDERS, $order, 'c2-'));
    }

    public function testABrowseEventCarriesThePrefixOnItsSkuAndOneWithoutASkuIsUnchanged(): void
    {
        $view = [
            'event_id' => '9b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c',
            'session_id' => 's1',
            'event_type' => 'product_view',
            'sku' => 'MJ01',
            'category_path' => 'men/tops',
        ];
        $search = [
            'event_id' => '8b2f6c3a-1d4e-4f5a-8b6c-7d8e9f0a1b2c',
            'event_type' => 'search',
            'search_query' => 'mj',
        ];

        $expected = $view;
        $expected['sku'] = 'c2-MJ01';

        self::assertSame($expected, ConnectionPrefix::item(Client::DOMAIN_BROWSE, $view, 'c2-'));
        self::assertSame($search, ConnectionPrefix::item(Client::DOMAIN_BROWSE, $search, 'c2-'));
    }

    public function testACustomerCarriesNoPrefix(): void
    {
        $customer = ['email' => 'shopper@example.invalid', 'external_id' => '42', 'website' => 'base'];

        self::assertSame($customer, ConnectionPrefix::item(Client::DOMAIN_CUSTOMERS, $customer, 'c2-'));
    }

    public function testWithoutAPrefixEveryItemGoesAsItWasQueued(): void
    {
        foreach ([
            Client::DOMAIN_CATALOG => $this->catalogRow(),
            Client::DOMAIN_ORDERS => $this->order(),
        ] as $domain => $item) {
            self::assertSame(
                json_encode($item),
                json_encode(ConnectionPrefix::item($domain, $item, '')),
                'Byte-identical: ' . $domain
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogRow(): array
    {
        return [
            'event_id' => 'b3f1a2c4-6d1e-4f7a-9b2c-3e5d7f9a1b2c',
            'sku' => 'MJ01-XS-Blue',
            'name' => 'Beaumont Summit Kit',
            'category_path' => 'men/tops/jackets',
            'price' => 42.0,
            'currency' => 'EUR',
            'in_stock' => true,
            'product_url' => 'https://shop.example/beaumont-summit-kit.html',
            'external_id' => '5',
            'tags' => ['product_id' => '1', 'type' => 'configurable'],
            'raw_attributes' => ['color' => 'Blue', 'size' => 'XS'],
            'websites' => ['base' => ['product_url' => ['en' => 'https://shop.example/beaumont-summit-kit.html']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function order(): array
    {
        return [
            'event_id' => 'c4e2b3d5-7f2a-4b8c-9d3e-5f6a7b8c9d0e',
            'external_order_id' => '000000123',
            'customer_email' => 'shopper@example.invalid',
            'ordered_at' => '2026-11-17T10:15:23Z',
            'total_amount' => 89.5,
            'currency' => 'EUR',
            'status' => 'completed',
            'items' => [
                ['sku' => 'MJ01-XS-Blue', 'qty' => 1, 'unit_price' => 42.0, 'line_total' => 42.0],
                ['sku' => '24-WG085', 'qty' => 2, 'unit_price' => 23.75, 'line_total' => 47.5],
            ],
            'website' => 'base',
        ];
    }
}
