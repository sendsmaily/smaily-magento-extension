<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\ViewModel;

use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\ViewModel\ProductContext;

class ProductContextTest extends TestCase
{
    /**
     * PRO-2461: a product page keys `product_view` the way the catalog row
     * and the order lines key the product — its SKU, or `mag-<entity_id>`
     * when the SKU is empty (CatalogPayloadBuilder::sku()). Without the
     * fallback the tracker leaves `sku` out and the view joins no product.
     *
     * @dataProvider skuProvider
     */
    public function testProductPageKeysTheProductAsTheCatalogDoes(?string $sku, string $expected): void
    {
        $product = $this->createMock(Product::class);
        $product->method('getSku')->willReturn($sku);
        $product->method('getId')->willReturn('42');

        $registry = $this->createMock(Registry::class);
        $registry->method('registry')->willReturnMap([
            ['current_category', null],
            ['current_product', $product],
        ]);

        $json = (new ProductContext($registry, new Json()))->getContextJson();

        self::assertSame(['type' => 'product', 'sku' => $expected], json_decode((string)$json, true));
    }

    /**
     * @return array<string, array{?string, string}>
     */
    public static function skuProvider(): array
    {
        return [
            'SKU' => ['TENT-1', 'TENT-1'],
            'empty SKU' => ['', 'mag-42'],
            'blank SKU' => ['  ', 'mag-42'],
            'no SKU' => [null, 'mag-42'],
        ];
    }
}
