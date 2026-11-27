<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Exception\EngineException;
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
 * two delivery queues and the abandoned-cart side table (PRO-2452) — and
 * the discount codes the module made for the address (CON-225).
 * Idempotent: repeating either is safe. Smaily marketing-contact deletion
 * is done in the Smaily UI; Magento's own customer data is handled by
 * Magento's native tooling.
 *
 * The Campaign Intelligence half goes to every connection (CON-237): a
 * shopper may be in the engine store of each separate store. With one
 * connection the output is as it always was; with several, the export's
 * `engine` holds each connected engine store's record under its name, and
 * each engine line starts with that name.
 */
class GdprCommand extends Command
{
    /**
     * Said instead of the engine's refusal while Campaign Intelligence
     * refuses the account (contract §2 `403 tenant_inactive`, PRO-2451):
     * the engine is not asked, the store's own data is still handled
     * (PRO-2465).
     */
    private const ACCOUNT_NOT_ACTIVE = 'the Campaign Intelligence account is not active.'
        . ' Ask Smaily to make the account active again, then run the command again.';

    public function __construct(
        private readonly Connections $connections,
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
        $several = count($this->connections->all()) > 1;
        $records = [];
        $failures = [];
        foreach ($this->connections->all() as $connection) {
            $settings = $connection->settings();
            if ($settings->isRefused()) {
                $failures[] = $this->prefix($connection, $several)
                    . 'Campaign Intelligence data was not exported: ' . self::ACCOUNT_NOT_ACTIVE;
            } elseif ($settings->isConnected()) {
                try {
                    $records[$this->name($connection)] = $this->connections->client($connection)
                        ->customerExport($email);
                } catch (EngineException $exception) {
                    $failures[] = $this->prefix($connection, $several) . $exception->getMessage();
                }
            }
        }
        $engine = $several ? ($records === [] ? null : $records) : (array_values($records)[0] ?? null);

        $output->writeln((string)json_encode(
            ['engine' => $engine, 'local' => $this->localEraser->export($email)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        foreach ($failures as $failure) {
            $output->writeln(sprintf('<error>%s</error>', $failure));
        }

        return $failures === [] ? Command::SUCCESS : Command::FAILURE;
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

        $connected = $this->connections->connected();
        if ($connected === []) {
            $output->writeln('<comment>Campaign Intelligence is not connected; no engine data to erase.</comment>');

            return Command::SUCCESS;
        }

        $several = count($this->connections->all()) > 1;
        $status = Command::SUCCESS;
        foreach ($connected as $connection) {
            if (!$this->eraseAt($connection, $this->prefix($connection, $several), $email, $output)) {
                $status = Command::FAILURE;
            }
        }

        return $status;
    }

    /**
     * Erase the shopper at one connection's engine store; false when it
     * was not erased there.
     */
    private function eraseAt(Connection $connection, string $prefix, string $email, OutputInterface $output): bool
    {
        if ($connection->settings()->isRefused()) {
            $output->writeln(sprintf(
                '<error>%sLocal data is erased, but Campaign Intelligence data was not: %s</error>',
                $prefix,
                self::ACCOUNT_NOT_ACTIVE
            ));

            return false;
        }

        try {
            $result = $this->connections->client($connection)->customerDelete($email);
        } catch (EngineException $exception) {
            $output->writeln(sprintf(
                '<error>%sLocal data is erased, but the Campaign Intelligence erasure failed: %s. '
                . 'Run the command again to retry it.</error>',
                $prefix,
                $exception->getMessage()
            ));

            return false;
        }

        $output->writeln(sprintf(
            '<info>%sErased engine data for %s.%s</info>',
            $prefix,
            $email,
            !empty($result['already_deleted']) ? ' (was already deleted)' : ''
        ));

        return true;
    }

    /** A connection's engine store by name: its tenant name, else its tenant id. */
    private function name(Connection $connection): string
    {
        $settings = $connection->settings();

        return $settings->getTenantName() !== '' ? $settings->getTenantName() : $settings->getTenantId();
    }

    /** What an engine line starts with: the engine store's name when there are several connections. */
    private function prefix(Connection $connection, bool $several): string
    {
        return $several ? $this->name($connection) . ': ' : '';
    }
}
