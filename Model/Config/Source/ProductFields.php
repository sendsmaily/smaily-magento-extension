<?php

namespace Smaily\SmailyForMagento\Model\Config\Source;

class ProductFields implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get Option values for Product field.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'first_name',
                'label' => 'Customer First Name'
            ],
            [
                'value' => 'last_name',
                'label' => 'Customer Last Name'
            ],
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
                'label' => 'Product SKU',
            ],
            [
                'value' => 'qty',
                'label' => 'Product Quantity',
            ],
            [
                'value' => 'price',
                'label' => 'Product Price',
            ],
            [
                'value' => 'base_price',
                'label' => 'Product Base Price',
            ],
        ];
    }
}
