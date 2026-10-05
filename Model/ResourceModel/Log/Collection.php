<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ResourceModel\Log;

use Magento\Framework\Data\Collection\Db\FetchStrategyInterface as FetchStrategy;
use Magento\Framework\Data\Collection\EntityFactoryInterface as EntityFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\View\Element\UiComponent\DataProvider\SearchResult;
use Psr\Log\LoggerInterface as Logger;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Engine\IngestEvent as IngestEventResource;
use Smaily\Connect\Model\ResourceModel\Queue\Event as EventResource;

/**
 * Unified log collection: one grid over BOTH delivery queues. The two
 * tables share the operational column set (status/attempts/last_error/
 * timestamps), so the rows are merged with a plain UNION ALL wrapped as a
 * derived table — grid filters and sorting apply to the outer select.
 * "source" tells the queues apart; "log_id" ("smaily-<id>" /
 * "intelligence-<id>") keys rows uniquely across both tables and routes
 * mass retries back to the right queue.
 */
class Collection extends SearchResult
{
    public const SOURCE_SMAILY = 'smaily';
    public const SOURCE_INTELLIGENCE = 'intelligence';

    /**
     * A row the store withdrew (PRO-2453) reads as its own status in the
     * grid: it was never delivered, so "Sent" would be a lie, and it never
     * failed either. Derived from the stored marker, not a queue status —
     * the flusher still sees the terminal `sent` row it wrote.
     */
    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * A row the queue closed without sending anything (PRO-3619): nothing
     * was delivered, so "Sent" would be a lie here too. Stored as a terminal
     * `sent` row with the reason in last_error — a delivered row never keeps
     * one (PRO-3565).
     */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * The status the Log shows for a row of the marketing queue, as SQL over
     * its columns (prefixed with $alias): a sent row with the withdrawal
     * marker reads Withdrawn, one with a skip reason Skipped, any other row
     * its stored status. Withdrawn wins: a withdrawn row may keep an earlier
     * attempt's error. The grid and the Dashboard's recent activity both
     * read it from here.
     */
    public static function smailyStatusExpression(AdapterInterface $connection, string $alias): \Zend_Db_Expr
    {
        return new \Zend_Db_Expr(sprintf(
            'CASE WHEN %5$s.status = %1$s AND %5$s.last_response = %2$s THEN %3$s'
            . ' WHEN %5$s.status = %1$s AND %5$s.last_error <> \'\' THEN %4$s ELSE %5$s.status END',
            $connection->quote(Event::STATUS_SENT),
            $connection->quote(EventQueue::CANCELLED_RESPONSE),
            $connection->quote(self::STATUS_WITHDRAWN),
            $connection->quote(self::STATUS_SKIPPED),
            $alias
        ));
    }

    /**
     * Split a composite log id back into its queue and row id. Unknown
     * sources and malformed ids come back as ['', 0].
     *
     * @return array{0: string, 1: int}
     */
    public static function splitLogId(string $logId): array
    {
        [$source, $id] = array_pad(explode('-', $logId, 2), 2, '');
        $known = $source === self::SOURCE_SMAILY || $source === self::SOURCE_INTELLIGENCE;

        return $known && (int)$id > 0 ? [$source, (int)$id] : ['', 0];
    }

    /**
     * @inheritDoc
     */
    public function __construct(
        EntityFactory $entityFactory,
        Logger $logger,
        FetchStrategy $fetchStrategy,
        EventManager $eventManager,
        private readonly FailureMessage $failureMessage
    ) {
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            EventResource::TABLE_NAME,
            UnifiedRow::class
        );
    }

    /**
     * The error column's text filter matches what the column shows
     * (PRO-2509), not the stored value: Model\Queue\Failure's
     * `permanent_http_<code>:` prefix is not searched, and a client message
     * shown translated is found by its translated words too (FailureMessage
     * strips and translates the same way for display). A stored error that
     * the column shows as redacted JSON is never matched, so the filter
     * cannot find a row by a secret the column hides.
     *
     * @param string|array<int, mixed> $field
     * @param null|string|array<int|string, mixed> $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($field !== 'last_error' || !is_array($condition) || !isset($condition['like'])) {
            return parent::addFieldToFilter($field, $condition);
        }

        $connection = $this->getConnection();
        $like = (string)$condition['like'];
        // The grid's text filter sends "%<term>%" with % and _ escaped.
        $term = str_replace(['\\%', '\\_'], ['%', '_'], (string)preg_replace('/^%|%$/', '', $like));
        $shown = 'CASE WHEN main_table.last_error REGEXP \'^permanent_http_[0-9]+:\' THEN TRIM(LEADING \' \''
            . ' FROM SUBSTRING(main_table.last_error, LOCATE(\':\', main_table.last_error) + 1))'
            . ' ELSE main_table.last_error END';

        $matches = [];
        foreach ([$like, ...$this->failureMessage->storedPatternsShowing($term)] as $pattern) {
            $matches[] = $connection->quoteInto($shown . ' LIKE ?', $pattern);
        }
        $this->getSelect()->where(sprintf(
            '(%1$s) AND NOT (JSON_VALID(%2$s) AND LEFT(%2$s, 1) IN (\'{\', \'[\'))',
            implode(' OR ', $matches),
            $shown
        ));

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function _initSelect()
    {
        $union = $this->getConnection()->select()->union(
            [
                $this->sourceSelect(
                    $this->getTable(EventResource::TABLE_NAME),
                    self::SOURCE_SMAILY,
                    'event_type'
                ),
                $this->sourceSelect(
                    $this->getTable(IngestEventResource::TABLE_NAME),
                    self::SOURCE_INTELLIGENCE,
                    'domain'
                ),
            ],
            Select::SQL_UNION_ALL
        );
        $this->getSelect()->from(['main_table' => $union]);
    }

    /**
     * The normalized per-queue half of the union.
     */
    private function sourceSelect(string $table, string $source, string $typeColumn): Select
    {
        $connection = $this->getConnection();

        return $connection->select()->from(
            ['q' => $table],
            [
                'log_id' => new \Zend_Db_Expr(
                    'CONCAT(' . $connection->quote($source . '-') . ', q.id)'
                ),
                'source' => new \Zend_Db_Expr($connection->quote($source)),
                'type' => 'q.' . $typeColumn,
                'entity_id' => 'q.entity_id',
                // Only the marketing queue withdraws or skips rows, so only
                // its half can carry either marker; the grid label and the
                // status filter both read this one derived column.
                'status' => $source === self::SOURCE_SMAILY
                    ? self::smailyStatusExpression($connection, 'q')
                    : 'q.status',
                'attempts' => 'q.attempts',
                'last_error' => 'q.last_error',
                'created_at' => 'q.created_at',
                'updated_at' => 'q.updated_at',
            ]
        );
    }
}
