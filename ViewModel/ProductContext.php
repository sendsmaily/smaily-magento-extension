<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * Page context for the browse tracker on catalog pages. The rendered values
 * are FPC-cached per page, which is exactly right — they describe the page,
 * not the visitor.
 */
class ProductContext implements ArgumentInterface
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Json $serializer
    ) {
    }

    public function getContextJson(): ?string
    {
        $category = $this->registry->registry('current_category');
        $product = $this->registry->registry('current_product');

        if ($product instanceof Product) {
            $context = ['type' => 'product', 'sku' => (string)$product->getSku()];
            if ($category instanceof Category) {
                $context['categoryPath'] = $this->categoryPath($category);
            }

            return $this->serializer->serialize($context);
        }

        if ($category instanceof Category) {
            return $this->serializer->serialize([
                'type' => 'category',
                'categoryPath' => $this->categoryPath($category),
            ]);
        }

        return null;
    }

    private function categoryPath(Category $category): string
    {
        $urlPath = (string)$category->getData('url_path');
        if ($urlPath !== '') {
            return $urlPath;
        }

        return (string)($category->getData('url_key') ?: strtolower((string)$category->getName()));
    }
}
