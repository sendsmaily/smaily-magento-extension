<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:backfill:start contacts [--website=1]
 *
 * Queues a chunked historical import; the smaily_backfill_tick cron job
 * advances it. Progress: smaily:backfill:status. A catalog import needs a
 * Campaign Intelligence connection (PRO-1969).
 */
class BackfillStartCommand extends Command
{
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly EngineSettings $engineSettings
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:backfill:start')
            ->setDescription('Start a Smaily historical import (backfill) job')
            ->addArgument(
                'type',
                InputArgument::OPTIONAL,
                'Job type: contacts (Smaily) or catalog|customers|orders (Campaign Intelligence)',
                Job::TYPE_CONTACTS
            )
            ->addOption(
                'website',
                'w',
                InputOption::VALUE_REQUIRED,
                'Website ID for contacts jobs (omit to start a job for every website)'
            );
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobType = (string)$input->getArgument('type');
        $target = Job::TYPE_TARGETS[$jobType] ?? null;
        if ($target === null) {
            $output->writeln(sprintf('<error>Unknown job type "%s".</error>', $jobType));

            return Command::FAILURE;
        }

        if ($jobType === Job::TYPE_CATALOG && !$this->engineSettings->isConnected()) {
            $output->writeln(
                '<error>Campaign Intelligence is not connected, so there is nowhere to send the catalog.</error>'
            );

            return Command::FAILURE;
        }

        if ($target === Job::TARGET_ENGINE) {
            $websiteIds = [Job::ENGINE_WEBSITE_ID];
        } else {
            $websiteOption = $input->getOption('website');
            $websiteIds = $websiteOption !== null
                ? [(int)$websiteOption]
                : array_map(static fn ($website) => (int)$website->getId(), $this->storeManager->getWebsites());
        }

        $started = 0;
        foreach ($websiteIds as $websiteId) {
            try {
                $job = $this->jobManager->start($jobType, $target, $websiteId);
                $output->writeln(sprintf(
                    '<info>Started %s backfill #%d for website %d.</info>',
                    $jobType,
                    (int)$job->getId(),
                    $websiteId
                ));
                $started++;
            } catch (\RuntimeException $exception) {
                $output->writeln(sprintf('<comment>%s</comment>', $exception->getMessage()));
            }
        }

        return $started > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
