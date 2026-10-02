<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Privacy\LocalEraser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:gdpr <export|erase> <email> [--force]
 *
 * Data-subject tooling: Art. 15 export and Art. 17 erasure, covering both
 * the Campaign Intelligence record and the module's OWN local tables — the
 * two delivery queues and the abandoned-cart side table (PRO-2452).
 * Idempotent: repeating either is safe. Smaily marketing-contact deletion
 * is done in the Smaily UI; Magento's own customer data is handled by
 * Magento's native tooling.
 */
class GdprCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly LocalEraser $localEraser
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:gdpr')
            ->setDescription('Export or erase Smaily Connect data for a customer email')
            ->addArgument('action', InputArgument::REQUIRED, 'export or erase')
            ->addArgument('email', InputArgument::REQUIRED, 'Customer email address')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Confirm erasure');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = (string)$input->getArgument('action');
        $email = strtolower(trim((string)$input->getArgument('email')));

        if ($action === 'export') {
            return $this->export($email, $output);
        }

        if ($action === 'erase') {
            if (!$input->getOption('force')) {
                $output->writeln('<comment>Add --force to confirm the irreversible erasure.</comment>');

                return Command::FAILURE;
            }

            return $this->erase($email, $output);
        }

        $output->writeln('<error>Unknown action; use export or erase.</error>');

        return Command::FAILURE;
    }

    /**
     * The engine's stored record plus the local rows the erasure would
     * touch, so export and erase cover the same set.
     */
    private function export(string $email, OutputInterface $output): int
    {
        $engine = null;
        $failure = null;
        if ($this->settings->isConnected()) {
            try {
                $engine = $this->client->customerExport($email);
            } catch (EngineException $exception) {
                $failure = $exception->getMessage();
            }
        }

        $output->writeln((string)json_encode(
            ['engine' => $engine, 'local' => $this->localEraser->export($email)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        if ($failure !== null) {
            $output->writeln(sprintf('<error>%s</error>', $failure));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Local first, engine second: a failing engine must never leave the
     * store's own copies of the address behind.
     */
    private function erase(string $email, OutputInterface $output): int
    {
        foreach ($this->localEraser->erase($email) as $label => $counts) {
            $output->writeln(sprintf(
                '<info>%s: %d removed, %d anonymized</info>',
                $label,
                $counts['removed'],
                $counts['anonymised']
            ));
        }

        if (!$this->settings->isConnected()) {
            $output->writeln('<comment>Campaign Intelligence is not connected; no engine data to erase.</comment>');

            return Command::SUCCESS;
        }

        try {
            $result = $this->client->customerDelete($email);
        } catch (EngineException $exception) {
            $output->writeln(sprintf(
                '<error>Local data is erased, but the Campaign Intelligence erasure failed: %s. '
                . 'Run the command again to retry it.</error>',
                $exception->getMessage()
            ));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Erased engine data for %s.%s</info>',
            $email,
            !empty($result['already_deleted']) ? ' (was already deleted)' : ''
        ));

        return Command::SUCCESS;
    }
}
