<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use Smaily\Connect\Model\Config\Source\SyncMode;

/**
 * Who the contact import sends for a website — the audience the live sync
 * covers under the website's contact-sync mode (PRO-3582, as the WooCommerce
 * plugin's ContactAudience):
 *
 * - consent: the website's newsletter subscribers, subscribed or
 *   unsubscribed — the two statuses Observer\SubscriberSaveAfter sends;
 * - legitimate_interest: those subscribers, then every other registered
 *   customer of the website (Observer\CustomerSaveAfter), as not subscribed;
 * - checkout_optin: nobody — a contact reaches Smaily only when a shopper
 *   ticks the checkout checkbox.
 *
 * Each row carries the contact's real subscription status, so nobody becomes
 * subscribed by being imported. count() reads the same two queries the pages
 * walk, so the estimate in the admin and the import never disagree.
 */
class ContactAudience
{
    private const SENT_STATUSES = [Subscriber::STATUS_SUBSCRIBED, Subscriber::STATUS_UNSUBSCRIBED];

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function count(int $websiteId, string $mode): int
    {
        $storeIds = $this->storeIds($websiteId);
        if ($mode === SyncMode::MODE_CHECKOUT_OPTIN || !$storeIds) {
            return 0;
        }

        $count = $this->countRows($this->subscribers($storeIds));
        if ($mode === SyncMode::MODE_LEGITIMATE_INTEREST) {
            $count += $this->countRows($this->otherCustomers($websiteId, $storeIds));
        }

        return $count;
    }

    /**
     * The website's subscribed and unsubscribed newsletter subscribers after
     * $afterId, by subscriber id.
     *
     * @return list<array{id: int, email: string, store_id: int, customer_id: int, subscribed: bool}>
     */
    public function subscriberPage(int $websiteId, int $afterId, int $limit): array
    {
        $storeIds = $this->storeIds($websiteId);
        if (!$storeIds) {
            return [];
        }

        $select = $this->subscribers($storeIds)
            ->columns(['subscriber_id', 'subscriber_email', 'store_id', 'customer_id', 'subscriber_status'])
            ->where('ns.subscriber_id > ?', $afterId)
            ->order('ns.subscriber_id ASC')
            ->limit($limit);

        $rows = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $rows[] = [
                'id' => (int)$row['subscriber_id'],
                'email' => (string)$row['subscriber_email'],
                'store_id' => (int)$row['store_id'],
                'customer_id' => (int)$row['customer_id'],
                'subscribed' => (int)$row['subscriber_status'] === Subscriber::STATUS_SUBSCRIBED,
            ];
        }

        return $rows;
    }

    /**
     * The website's registered customers the subscriber page does not
     * already send, after $afterId, by customer id. None of them is
     * subscribed on this website. A customer without a store of this website
     * (an account created in the admin) goes through its default store.
     *
     * @return list<array{id: int, email: string, store_id: int, customer_id: int, subscribed: bool}>
     */
    public function customerPage(int $websiteId, int $afterId, int $limit): array
    {
        $storeIds = $this->storeIds($websiteId);
        if (!$storeIds) {
            return [];
        }
        $defaultStoreId = (int)$this->website($websiteId)?->getDefaultStore()?->getId();

        $select = $this->otherCustomers($websiteId, $storeIds)
            ->columns(['entity_id', 'email', 'store_id'])
            ->where('e.entity_id > ?', $afterId)
            ->order('e.entity_id ASC')
            ->limit($limit);

        $rows = [];
        foreach ($this->connection()->fetchAll($select) as $row) {
            $storeId = (int)$row['store_id'];
            $rows[] = [
                'id' => (int)$row['entity_id'],
                'email' => (string)$row['email'],
                'store_id' => in_array($storeId, $storeIds, true) ? $storeId : $defaultStoreId,
                'customer_id' => (int)$row['entity_id'],
                'subscribed' => false,
            ];
        }

        return $rows;
    }

    /**
     * @return int[]
     */
    public function storeIds(int $websiteId): array
    {
        $website = $this->website($websiteId);

        return $website ? array_values(array_map('intval', $website->getStoreIds())) : [];
    }

    /**
     * @param int[] $storeIds
     */
    private function subscribers(array $storeIds): Select
    {
        return $this->connection()->select()
            ->from(['ns' => $this->resourceConnection->getTableName('newsletter_subscriber')], [])
            ->where('ns.store_id IN (?)', $storeIds)
            ->where('ns.subscriber_status IN (?)', self::SENT_STATUSES);
    }

    /**
     * Customers of the website with no subscriber row the subscriber page
     * sends — matched by customer id, or by address for a guest subscription
     * that was never linked to the account.
     *
     * @param int[] $storeIds
     */
    private function otherCustomers(int $websiteId, array $storeIds): Select
    {
        $sent = $this->subscribers($storeIds)
            ->columns(['subscriber_id'])
            ->where('ns.customer_id = e.entity_id OR ns.subscriber_email = e.email');

        return $this->connection()->select()
            ->from(['e' => $this->resourceConnection->getTableName('customer_entity')], [])
            ->where('e.website_id = ?', $websiteId)
            ->where('NOT EXISTS (' . $sent->assemble() . ')');
    }

    private function countRows(Select $select): int
    {
        return (int)$this->connection()->fetchOne($select->columns([new \Zend_Db_Expr('COUNT(*)')]));
    }

    private function website(int $websiteId): ?Website
    {
        try {
            $website = $this->storeManager->getWebsite($websiteId);
        } catch (\Exception) {
            return null;
        }

        return $website instanceof Website ? $website : null;
    }

    private function connection(): \Magento\Framework\DB\Adapter\AdapterInterface
    {
        return $this->resourceConnection->getConnection();
    }
}
