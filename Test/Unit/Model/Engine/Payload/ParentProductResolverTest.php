<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine\Payload;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Engine\Payload\ParentProductResolver;

class ParentProductResolverTest extends TestCase
{
    private AdapterInterface&MockObject $connection;

    /** @var int how many times the super-link lookup ran */
    private int $lookups = 0;

    /** @var array<int, mixed> the product id of each category-product query */
    private array $categoryLookups = [];

    public function testConfigurableChildResolvesToItsParentEntityId(): void
    {
        $resolver = $this->createResolver('42');

        self::assertSame('42', $resolver->productIdOf(7));
        self::assertTrue($resolver->isConfigurableChild(7));
    }

    public function testStandaloneProductKeysItself(): void
    {
        $resolver = $this->createResolver(false);

        self::assertSame('9', $resolver->productIdOf(9));
        self::assertFalse($resolver->isConfigurableChild(9));
    }

    public function testResolutionIsMemoizedPerEntity(): void
    {
        $resolver = $this->createResolver('42');

        $resolver->productIdOf(7);
        $resolver->isConfigurableChild(7);
        $resolver->productIdOf(7);

        self::assertSame(1, $this->lookups, 'One DB lookup per entity per request');
    }

    public function testLookupFailureFallsBackToSelfKeying(): void
    {
        // Resolution must never block a payload — pre-PRO-1231 behavior.
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')
            ->willThrowException(new \RuntimeException('db gone'));
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool->method('getMetadata')->willReturn($metadata);

        $resolver = new ParentProductResolver($resourceConnection, $metadataPool);

        self::assertSame('7', $resolver->productIdOf(7));
        self::assertFalse($resolver->isConfigurableChild(7));
    }

    public function testNonPositiveIdsNeverHitTheDatabase(): void
    {
        $resolver = $this->createResolver('42');

        self::assertSame('0', $resolver->productIdOf(0));
        self::assertFalse($resolver->isConfigurableChild(0));
        self::assertSame(0, $this->lookups);
    }

    /**
     * PRO-3714: a variant reads its parent's category ids with one query per
     * parent, shared by its siblings.
     */
    public function testAVariantReadsItsParentsCategoryIdsOncePerParent(): void
    {
        $resolver = $this->createResolver('42', ['5', '9']);

        self::assertSame([5, 9], $resolver->parentCategoryIds(7));
        self::assertSame([5, 9], $resolver->parentCategoryIds(8));
        self::assertSame([42], $this->categoryLookups, 'One category query for the shared parent');
    }

    /**
     * PRO-3714: a product that is not a variant has no parent categories and
     * runs no category query.
     */
    public function testAProductThatIsNotAVariantHasNoParentCategories(): void
    {
        $resolver = $this->createResolver(false, ['5']);

        self::assertSame([], $resolver->parentCategoryIds(9));
        self::assertSame([], $this->categoryLookups);
    }

    /**
     * PRO-3768: a Delete bunch's configurable children come from one query
     * for the whole bunch, and the answers are memoized as one lookup each
     * would have left them.
     */
    public function testConfigurableChildrenOfManyProductsComeFromOneQuery(): void
    {
        $resolver = $this->createResolver(false);
        $pairsQueries = 0;
        $this->connection->method('fetchPairs')->willReturnCallback(
            static function () use (&$pairsQueries): array {
                $pairsQueries++;

                return ['11' => '10', '12' => '10'];
            }
        );

        self::assertSame([11, 12], $resolver->configurableChildIds([10, 11, 0, 12, 20, 11]));
        self::assertSame([11], $resolver->configurableChildIds([11, 20]));
        self::assertSame('10', $resolver->productIdOf(12));
        self::assertSame('20', $resolver->productIdOf(20));
        self::assertSame(1, $pairsQueries);
        self::assertSame(0, $this->lookups, 'No per-product lookup');
    }

    /**
     * @param string|false $fetchOneResult parent entity id or false (no row)
     * @param string[] $categoryIds what the category-product query answers
     */
    private function createResolver(string|false $fetchOneResult, array $categoryIds = []): ParentProductResolver
    {
        $this->lookups = 0;
        $this->categoryLookups = [];
        $whereProductId = null;

        $select = $this->createMock(Select::class);
        foreach (['from', 'join', 'order', 'limit', 'group'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select, &$whereProductId) {
                $whereProductId = $value;

                return $select;
            }
        );

        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchOne')->willReturnCallback(
            function () use ($fetchOneResult) {
                $this->lookups++;

                return $fetchOneResult;
            }
        );
        $this->connection->method('fetchCol')->willReturnCallback(
            function () use ($categoryIds, &$whereProductId) {
                $this->categoryLookups[] = $whereProductId;

                return $categoryIds;
            }
        );

        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($this->connection);
        $resourceConnection->method('getTableName')->willReturnArgument(0);

        $metadata = $this->createMock(EntityMetadataInterface::class);
        $metadata->method('getLinkField')->willReturn('entity_id');
        $metadataPool = $this->createMock(MetadataPool::class);
        $metadataPool->method('getMetadata')->willReturn($metadata);

        return new ParentProductResolver($resourceConnection, $metadataPool);
    }
}
