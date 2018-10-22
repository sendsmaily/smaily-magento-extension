<?php

namespace Magento\Smaily\Model\Config\Source;

class ProductFields implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for Product field.
     *
     * @return array
     */
    public function toOptionArray()
    {
        $list = [
            [
                'value' => 'name',
                'label' => 'Product Name',
            ],
            [
                'value' => 'description',
                'label' => 'Product Description',
            ],
            [
                'value' => 'sku',
                'label' => 'SKU',
            ],
            [
                'value' => 'qty',
                'label' => 'Qty',
            ],
            [
                'value' => 'price',
                'label' => 'Price',
            ],
            [
                'value' => 'base_price',
                'label' => 'Base Price',
            ],
        ];

        return $list;
    }
}
