<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Log;

use Magento\Framework\DB\Select;
use Smaily\Connect\Model\Engine\Queue\IngestQueue;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * The Log's mass Retry over a grid selection of any size (PRO-2510). The
 * selection — the grid's filters plus the ticked or excluded rows — stays a
 * query: its failed rows are read once, as log ids only, and the guard and
 * the queues are asked about them one batch of one queue at a time. No list
 * of every selected row is built, and no IN (...) is longer than a batch.
 *
 * Every row still passes ResendGuard (PRO-2454): a refused row is left alone
 * and counted. The guard reads a row against the queue as stored, and a
 * retried row turns pending, never delivered, so a batch's answer does not
 * depend on the batches before it.
 */
class SelectionRetry
{
    public const BATCH_SIZE = 1000;

    public function __construct(
        private readonly EventQueue $eventQueue,
        private readonly IngestQueue $ingestQueue,
        private readonly QueueRowLoader $rowLoader,
        private readonly ResendGuard $resendGuard
    ) {
    }

    /**
     * Retry the failed rows of a selection of the Log grid.
     *
     * @return array{0: int, 1: int} rows queued for retry, rows the guard refused
     */
    public function retry(Collection $selection): array
    {
        $select = clone $selection->getSelect();
        $select->reset(Select::COLUMNS)
            ->reset(Select::ORDER)
            ->reset(Select::LIMIT_COUNT)
            ->reset(Select::LIMIT_OFFSET)
            ->columns('log_id', 'main_table')
            ->where('main_table.status = ?', Event::STATUS_FAILED);

        $outcomes = [];
        $batches = [Collection::SOURCE_SMAILY => [], Collection::SOURCE_INTELLIGENCE => []];
        $statement = $selection->getConnection()->query($select);
        while (($logId = $statement->fetchColumn()) !== false) {
            [$source, $id] = Collection::splitLogId((string)$logId);
            if ($source === '') {
                continue;
            }
            $batches[$source][] = $id;
            if (count($batches[$source]) === self::BATCH_SIZE) {
                $outcomes[] = $this->retryBatch($source, $batches[$source]);
                $batches[$source] = [];
            }
        }
        foreach ($batches as $source => $ids) {
            $outcomes[] = $this->retryBatch($source, $ids);
        }

        return [(int)array_sum(array_column($outcomes, 0)), (int)array_sum(array_column($outcomes, 1))];
    }

    /**
     * @param int[] $ids failed rows of one queue
     * @return array{0: int, 1: int} rows of the batch queued for retry, rows the guard refused
     */
    private function retryBatch(string $source, array $ids): array
    {
        if (!$ids) {
            return [0, 0];
        }

        $refused = $this->resendGuard->refusalReasons($source, $this->rowLoader->loadFailed($source, $ids));
        $retry = array_values(array_diff($ids, array_keys($refused)));
        $retried = $source === Collection::SOURCE_SMAILY
            ? $this->eventQueue->retry($retry)
            : $this->ingestQueue->retry($retry);

        return [$retried, count($refused)];
    }
}
