<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ContactSync\Mode;
use Smaily\Connect\Model\Engine\Connection;

/**
 * The registered customers the Campaign Intelligence customers import sends
 * (contract v1.20.0 §4, CON-241): every customer the store's own contact
 * sync puts into Smaily — per website, by the website's sync mode, while
 * its contact sync is active (ContactAudience::customerCondition()) — and
 * every customer with an order placed since the history horizon. Every
 * other account is left out, for example under "Subscribers only" an
 * account that never subscribed and never bought. Smaily itself is never
 * asked. A buyer is matched by the order's customer id; a guest buyer
 * reaches the engine with the order, as before.
 *
 * The live customer sync asks the same rule for one customer
 * (includes(), Engine\CustomerIngest, CON-266), so the import and the
 * live sync cannot send different customers.
 *
 * The scope is a Campaign Intelligence connection's (CON-237): the
 * contact-sync groups of its own websites, and the buyers of an order
 * placed in one of its store views. A connection that serves every
 * website — every store run as one store — has the scope it always had,
 * read with the same query.
 */
class EngineCustomerScope
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly ContactAudience $audience,
        private readonly Mode $mode,
        private readonly Config $config
    ) {
    }

    /**
     * @param string $horizon the oldest order time inside the horizon (GMT)
     */
    public function count(string $horizon, Connection $engineConnection): int
    {
        $select = $this->select($horizon, $engineConnection)->columns(['cnt' => new \Zend_Db_Expr('COUNT(*)')]);

        return (int)$this->resourceConnection->getConnection()->fetchOne($select);
    }

    /**
     * The ids of the customers in scope after $afterId, ascending.
     *
     * @return int[]
     */
    public function page(string $horizon, int $afterId, int $limit, Connection $engineConnection): array
    {
        $select = $this->select($horizon, $engineConnection)
            ->columns(['entity_id' => 'e.entity_id'])
            ->where('e.entity_id > ?', $afterId)
            ->order('e.entity_id ASC')
            ->limit($limit);

        return array_map('intval', $this->resourceConnection->getConnection()->fetchCol($select));
    }

    /**
     * Whether one customer is in scope: the same rule as the import, read
     * for one id (one query on the customer's primary key).
     *
     * @param string $horizon the oldest order time inside the horizon (GMT)
     */
    public function includes(int $customerId, string $horizon, Connection $engineConnection): bool
    {
        if ($customerId <= 0) {
            return false;
        }
        $select = $this->select($horizon, $engineConnection)
            ->columns(['entity_id' => 'e.entity_id'])
            ->where('e.entity_id = ?', $customerId)
            ->limit(1);

        return $this->resourceConnection->getConnection()->fetchOne($select) !== false;
    }

    private function select(string $horizon, Connection $engineConnection): Select
    {
        $connection = $this->resourceConnection->getConnection();
        $all = $engineConnection->servesAllWebsites();
        $buyer = $connection->select()
            ->from(['o' => $this->resourceConnection->getTableName('sales_order')], ['entity_id'])
            ->where('o.customer_id = e.entity_id')
            ->where('o.created_at >= ?', $horizon);
        $conditions = [];
        if ($all) {
            $conditions[] = 'EXISTS (' . $buyer->assemble() . ')';
        } elseif ($engineConnection->storeIds()) {
            $buyer->where('o.store_id IN (?)', $engineConnection->storeIds());
            $conditions[] = 'EXISTS (' . $buyer->assemble() . ')';
        }
        foreach ($this->storeManager->getWebsites() as $website) {
            $websiteId = (int)$website->getId();
            if (!$all && !$engineConnection->servesWebsite($websiteId)) {
                continue;
            }
            if (!$this->config->isContactSyncActive($websiteId)) {
                continue;
            }
            $synced = $this->audience->customerCondition($websiteId, $this->mode->mode($websiteId));
            if ($synced !== null) {
                $conditions[] = $synced;
            }
        }

        return $connection->select()
            ->from(['e' => $this->resourceConnection->getTableName('customer_entity')], [])
            ->where($conditions ? implode(' OR ', $conditions) : '0 = 1');
    }
}
