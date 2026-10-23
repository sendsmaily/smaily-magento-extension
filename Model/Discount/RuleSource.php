<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Discount;

use Magento\SalesRule\Model\ResourceModel\Rule\Collection;
use Magento\SalesRule\Model\ResourceModel\Rule\CollectionFactory;
use Magento\SalesRule\Model\Rule;

/**
 * The merchant's cart price rules that an automation's discount codes can
 * come from (CON-198): active, Coupon "Specific Coupon" with "Use Auto
 * Generation" ticked (or the API-only coupon type Auto, which Magento's own
 * code generation accepts as well), and not ended. The rule keeps
 * everything else — the discount, its conditions, uses per customer,
 * customer groups — so nothing of it is read here.
 *
 * Read through Magento's rule collection, never the table, so a staged rule
 * of Adobe Commerce reads as Magento reads it.
 */
class RuleSource
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * The rules a discount code can come from on every one of $websiteIds
     * and that have not ended by $today (`Y-m-d`): rule id => name, by name.
     *
     * @param int[] $websiteIds
     * @return array<int, string>
     */
    public function forWebsites(array $websiteIds, string $today): array
    {
        $collection = $this->couponRules()->addWebsitesToResult();
        $collection->addWebsiteFilter($websiteIds);
        $collection->addFieldToFilter('main_table.to_date', [['null' => true], ['gteq' => $today]]);
        $collection->setOrder('main_table.name', Collection::SORT_ORDER_ASC);

        $rules = [];
        foreach ($collection as $rule) {
            $ruleWebsites = array_map('intval', (array)$rule->getWebsiteIds());
            if (array_diff($websiteIds, $ruleWebsites) === []) {
                $rules[(int)$rule->getId()] = (string)$rule->getName();
            }
        }

        return $rules;
    }

    /**
     * Whether the store has any cart price rule at all.
     */
    public function hasRules(): bool
    {
        return $this->collectionFactory->create()->getSize() > 0;
    }

    /**
     * The rule when a code can come from it for $websiteId on $today: its
     * To date (`Y-m-d`, '' for none) and its uses per customer. Null when it
     * is gone, inactive, no longer generates codes, is not for the website
     * or its dates do not include today.
     *
     * @return array{to_date: string, uses_per_customer: int}|null
     */
    public function usable(int $ruleId, int $websiteId, string $today): ?array
    {
        $collection = $this->couponRules();
        $collection->addFieldToFilter('main_table.rule_id', ['eq' => $ruleId]);
        $collection->addWebsiteFilter([$websiteId]);
        $collection->addFieldToFilter('main_table.from_date', [['null' => true], ['lteq' => $today]]);
        $collection->addFieldToFilter('main_table.to_date', [['null' => true], ['gteq' => $today]]);
        $rule = $collection->getFirstItem();
        if (!$rule->getId()) {
            return null;
        }

        return [
            'to_date' => substr((string)$rule->getToDate(), 0, 10),
            'uses_per_customer' => (int)$rule->getUsesPerCustomer(),
        ];
    }

    /**
     * Active rules whose codes Magento can generate.
     */
    private function couponRules(): Collection
    {
        $collection = $this->collectionFactory->create();
        $collection->addIsActiveFilter();
        $connection = $collection->getConnection();
        $collection->getSelect()->where(
            $connection->quoteInto('(main_table.coupon_type = ?', Rule::COUPON_TYPE_SPECIFIC)
            . ' AND main_table.use_auto_generation = 1)'
            . $connection->quoteInto(' OR main_table.coupon_type = ?', Rule::COUPON_TYPE_AUTO)
        );

        return $collection;
    }
}
