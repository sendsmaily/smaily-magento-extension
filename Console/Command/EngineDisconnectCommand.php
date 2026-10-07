<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Engine\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:engine:disconnect --force — removes the local
 * Campaign Intelligence tenant connection.
 */
class EngineDisconnectCommand extends Command
{
    public function __construct(
        private readonly Settings $settings
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:engine:disconnect')
            ->setDescription('Disconnect Campaign Intelligence (removes local credentials)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Confirm the disconnect');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->settings->isConnected()) {
            $output->writeln('<comment>Campaign Intelligence is not connected.</comment>');

            return Command::SUCCESS;
        }

        if (!$input->getOption('force')) {
            $output->writeln('<comment>Add --force to confirm disconnecting Campaign Intelligence.</comment>');

            return Command::FAILURE;
        }

        $this->settings->clear();
        $output->writeln('<info>Campaign Intelligence disconnected.</info>');

        return Command::SUCCESS;
    }
}
