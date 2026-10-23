<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Discount;

use Magento\Framework\DataObject;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Discount\RuleSource;

/**
 * CON-198: the rule picker lists a rule only when it is for every website
 * the settings send for.
 */
class RuleSourceTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../Support/Stub/SalesRuleCollectionFactory.php';
    }

    public function testARuleIsListedOnlyForEveryWebsiteTheSettingsSendFor(): void
    {
        $collection = $this->collection([
            new DataObject(['id' => 3, 'name' => 'Both websites', 'website_ids' => ['1', '2']]),
            new DataObject(['id' => 4, 'name' => 'Estonia only', 'website_ids' => ['1']]),
        ]);
        $collection->expects(self::once())->method('addWebsiteFilter')->with([1, 2]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        self::assertSame([3 => 'Both websites'], (new RuleSource($factory))->forWebsites([1, 2], '2026-10-23'));
    }

    public function testOneWebsiteListsItsRules(): void
    {
        $collection = $this->collection([
            new DataObject(['id' => 4, 'name' => 'Estonia only', 'website_ids' => ['1']]),
        ]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        self::assertSame([4 => 'Estonia only'], (new RuleSource($factory))->forWebsites([1], '2026-10-23'));
    }

    /**
     * @param DataObject[] $rules
     * @return Collection&\PHPUnit\Framework\MockObject\MockObject
     */
    private function collection(array $rules): Collection
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteInto')->willReturnCallback(
            static fn (string $text, mixed $value): string => str_replace('?', (string)$value, $text)
        );
        $collection = $this->createMock(Collection::class);
        $collection->method('addIsActiveFilter')->willReturnSelf();
        $collection->method('addWebsitesToResult')->willReturnSelf();
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setOrder')->willReturnSelf();
        $collection->method('getConnection')->willReturn($connection);
        $collection->method('getSelect')->willReturn($this->createMock(Select::class));
        $collection->method('getIterator')->willReturn(new \ArrayIterator($rules));

        return $collection;
    }
}
