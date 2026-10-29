<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\ShownReason;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:backfill:status — lists backfill jobs and progress.
 *
 * As the admin's import card: a Campaign Intelligence import's failed_count
 * holds the rows it skipped because Magento could not read them (CON-227),
 * so it shows under "Skipped (unreadable)", not "Failed"; the error is only
 * where it happened or one of the imports' own reasons, never an error's
 * text an earlier version stored (ShownReason, CON-233).
 */
class BackfillStatusCommand extends Command
{
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:backfill:status')
            ->setDescription('Show Smaily backfill job progress');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $collection = $this->collectionFactory->create();
        $collection->setOrder('id', 'DESC')->setPageSize(20);

        $table = new Table($output);
        $table->setHeaders([
            'ID', 'Type', 'Target', 'Website', 'Status', 'Progress', 'Failed', 'Skipped (unreadable)', 'Error',
        ]);
        foreach ($collection->getItems() as $job) {
            if (!$job instanceof Job) {
                continue;
            }
            $total = $job->getData('total_count');
            $engine = $job->getTarget() === Job::TARGET_ENGINE;
            $table->addRow([
                $job->getId(),
                $job->getJobType(),
                $job->getTarget(),
                $job->getWebsiteId(),
                $job->getStatus(),
                sprintf('%d / %s', $job->getProcessedCount(), $total === null ? '?' : (string)(int)$total),
                $engine ? 0 : $job->getFailedCount(),
                $engine ? $job->getFailedCount() : 0,
                ShownReason::of((string)$job->getData('error_message')),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
