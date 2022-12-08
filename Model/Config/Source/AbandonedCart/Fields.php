<?php

namespace Smaily\SmailyForMagento\Model\Config\Source\AbandonedCart;

class Fields implements \Magento\Framework\Option\ArrayInterface
{
    /**
     * Get options for Abandoned Cart fields.
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
                'value' => 'image_url',
                'label' => 'Product Image URL',
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
