<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\AbstractModel;

/**
 * Stores many queue rows whose models already hold their new values in one
 * UPDATE per CHUNK rows, instead of one save per row: how both queues record
 * one failure that a batch shares (EventQueue::markFailedMany(), PRO-1964;
 * Engine\Queue\IngestQueue::markFailedMany(), PRO-3962).
 */
class RowWriter
{
    /**
     * Rows per UPDATE: a flush batch of either queue (200 at most) fits in one.
     */
    public const CHUNK = 200;

    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Write the named columns of each row as its model holds them. A column
     * every row holds alike is set once, any other through CASE on the row
     * id, and a row whose model does not hold a column keeps it.
     *
     * @param AbstractModel[] $rows saved rows, each with its id
     * @param string[] $columns
     */
    public function write(string $tableName, array $rows, array $columns): void
    {
        $values = [];
        foreach ($rows as $row) {
            $values[(int)$row->getId()] = array_intersect_key((array)$row->getData(), array_flip($columns));
        }
        foreach (array_chunk($values, self::CHUNK, true) as $chunk) {
            $this->update($this->resourceConnection->getTableName($tableName), $chunk);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $rows column values by row id
     */
    private function update(string $table, array $rows): void
    {
        $connection = $this->resourceConnection->getConnection();
        $idColumn = $connection->quoteIdentifier('id');
        $columns = array_keys(array_merge(...array_values($rows)));

        $bind = [];
        foreach ($columns as $column) {
            $values = [];
            foreach ($rows as $id => $row) {
                if (array_key_exists($column, $row)) {
                    $values[$id] = $row[$column];
                }
            }
            $first = reset($values);
            $alike = count($values) === count($rows);
            foreach ($values as $value) {
                $alike = $alike && $value === $first;
            }
            if ($alike) {
                $bind[$column] = $first;
                continue;
            }

            $quotedColumn = $connection->quoteIdentifier($column);
            $cases = '';
            foreach ($values as $id => $value) {
                $cases .= sprintf(' WHEN %d THEN %s', $id, $value === null ? 'NULL' : $connection->quote($value));
            }
            $bind[$column] = new \Zend_Db_Expr(sprintf('CASE %s%s ELSE %s END', $idColumn, $cases, $quotedColumn));
        }

        $connection->update($table, $bind, ['id IN (?)' => array_keys($rows)]);
    }
}
