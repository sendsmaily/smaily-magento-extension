<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\ResourceModel\Backfill\Job\CollectionFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:backfill:status — lists backfill jobs and progress.
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
        $table->setHeaders(['ID', 'Type', 'Target', 'Website', 'Status', 'Progress', 'Failed', 'Error']);
        foreach ($collection->getItems() as $job) {
            if (!$job instanceof Job) {
                continue;
            }
            $total = $job->getData('total_count');
            $table->addRow([
                $job->getId(),
                $job->getJobType(),
                $job->getTarget(),
                $job->getWebsiteId(),
                $job->getStatus(),
                sprintf('%d / %s', $job->getProcessedCount(), $total === null ? '?' : (string)(int)$total),
                $job->getFailedCount(),
                mb_substr((string)$job->getData('error_message'), 0, 60),
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
