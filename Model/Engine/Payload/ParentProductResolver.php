<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Resolves the platform parent product id a catalog row carries as
 * `tags.product_id` (contract §3 identity): for a configurable child the
 * configurable PARENT's entity id — all variants of one product share it —
 * for anything else the product's own entity id. The value keys §3b
 * product-level removal, so it must be the exact string the catalog sync
 * emits (raw entity id, never the sku).
 *
 * The lookup goes straight to `catalog_product_super_link` joined through
 * the product entity's link field (row_id-safe on Adobe Commerce) instead
 * of depending on Magento_ConfigurableProduct. Grouped/bundle membership is
 * deliberately NOT a parent relation here — those children are standalone
 * products, not variants.
 */
class ParentProductResolver
{
    private const SUPER_LINK_TABLE = 'catalog_product_super_link';
    private const PRODUCT_ENTITY_TABLE = 'catalog_product_entity';
    private const CATEGORY_PRODUCT_TABLE = 'catalog_category_product';

    /**
     * Per-request memo: entity id -> emitted tags.product_id string.
     *
     * @var array<int, string>
     */
    private array $resolved = [];

    /**
     * Per-request memo: parent entity id -> its category ids.
     *
     * @var array<int, int[]>
     */
    private array $parentCategoryIds = [];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly MetadataPool $metadataPool
    ) {
    }

    /**
     * The `tags.product_id` value for a product entity id.
     */
    public function productIdOf(int $entityId): string
    {
        if ($entityId <= 0) {
            return (string)$entityId;
        }

        return $this->resolved[$entityId] ??= (string)($this->parentEntityId($entityId) ?? $entityId);
    }

    /**
     * Whether the product is a configurable child (its tags.product_id is
     * another product's id). Drives the delete-observer split: a child's
     * hard-delete must stay on the per-SKU soft path — §3b is product-level
     * and would tombstone the surviving parent and siblings.
     */
    public function isConfigurableChild(int $entityId): bool
    {
        return $entityId > 0 && $this->productIdOf($entityId) !== (string)$entityId;
    }

    /**
     * The category ids of a configurable child's parent product, or [] when
     * the product is not a configurable child (PRO-3714: a variant without
     * categories of its own is sent with its parent's category).
     *
     * One query on the category-product link table — the same rows
     * `Product::getCategoryIds()` reads — instead of loading the parent
     * product, memoized per parent so all siblings in a backfill page share
     * it. A failed lookup reads as no categories, never blocks a payload.
     *
     * @return int[]
     */
    public function parentCategoryIds(int $childId): array
    {
        if (!$this->isConfigurableChild($childId)) {
            return [];
        }
        $parentId = (int)$this->productIdOf($childId);

        if (!array_key_exists($parentId, $this->parentCategoryIds)) {
            try {
                $connection = $this->resourceConnection->getConnection();
                $select = $connection->select()
                    ->from($this->resourceConnection->getTableName(self::CATEGORY_PRODUCT_TABLE), ['category_id'])
                    ->where('product_id = ?', $parentId);
                $this->parentCategoryIds[$parentId] = array_map('intval', $connection->fetchCol($select));
            } catch (\Throwable) {
                $this->parentCategoryIds[$parentId] = [];
            }
        }

        return $this->parentCategoryIds[$parentId];
    }

    /**
     * Configurable parent entity id for a child, or null when the product
     * is not linked under any configurable. Multiple parents (a simple can
     * be reused across configurables) resolve to the lowest entity id so
     * the emitted key is deterministic.
     */
    private function parentEntityId(int $childId): ?int
    {
        try {
            $linkField = $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
            $connection = $this->resourceConnection->getConnection();
            $select = $connection->select()
                ->from(['link' => $this->resourceConnection->getTableName(self::SUPER_LINK_TABLE)], [])
                ->join(
                    ['parent' => $this->resourceConnection->getTableName(self::PRODUCT_ENTITY_TABLE)],
                    'parent.' . $linkField . ' = link.parent_id',
                    ['entity_id']
                )
                ->where('link.product_id = ?', $childId)
                ->order('parent.entity_id ASC')
                ->limit(1);
            $parentId = (int)$connection->fetchOne($select);

            return $parentId > 0 ? $parentId : null;
        } catch (\Throwable) {
            // Resolution must never block a payload: fall back to
            // self-keying (the pre-PRO-1231 behavior for every product).
            return null;
        }
    }
}
