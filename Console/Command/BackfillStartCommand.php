<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\EngineImportGuard;
use Smaily\Connect\Model\Backfill\Job;
use Smaily\Connect\Model\Backfill\JobManager;
use Smaily\Connect\Model\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:backfill:start contacts [--website=1]
 *
 * Queues a chunked historical import; the smaily_backfill_tick cron job
 * advances it. Progress: smaily:backfill:status. A catalog, customers or
 * orders import needs a Campaign Intelligence connection (PRO-1969, PRO-3742).
 * A contacts import for a website whose contact sync is off still starts —
 * it sends nothing and finishes at 0, as the import itself decides
 * (PRO-1764) — and the command says so (PRO-1970).
 */
class BackfillStartCommand extends Command
{
    public function __construct(
        private readonly JobManager $jobManager,
        private readonly StoreManagerInterface $storeManager,
        private readonly EngineImportGuard $engineImportGuard,
        private readonly Config $config
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

        $refusal = $this->engineImportGuard->refusal($jobType, $target);
        if ($refusal !== null) {
            // English, as the command's other output.
            $output->writeln(sprintf('<error>%s</error>', $refusal->getText()));

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
            $job = $this->jobManager->startIfIdle($jobType, $target, $websiteId);
            if ($job === null) {
                $output->writeln(sprintf(
                    '<comment>%s</comment>',
                    $this->jobManager->alreadyActiveMessage($jobType, $target, $websiteId)
                ));
                continue;
            }
            $output->writeln(sprintf(
                '<info>Started %s backfill #%d for website %d.</info>',
                $jobType,
                (int)$job->getId(),
                $websiteId
            ));
            // The stored switch ContactsProcessor checks on every run
            // (PRO-1764): a notice, not a refusal (PRO-1970).
            if ($jobType === Job::TYPE_CONTACTS && !$this->config->isSyncEnabled($websiteId)) {
                $output->writeln(sprintf(
                    '<comment>Contact synchronization is off for website %d, so this import sends nothing and'
                    . ' finishes at 0. Turn on Sync contacts to Smaily under Settings > Contacts, then start'
                    . ' the import again.</comment>',
                    $websiteId
                ));
            }
            $started++;
        }

        return $started > 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
