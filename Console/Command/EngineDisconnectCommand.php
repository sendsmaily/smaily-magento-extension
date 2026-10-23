<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Console\Command;

use Smaily\Connect\Model\Engine\Disconnector;
use Smaily\Connect\Model\Engine\Settings;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:engine:disconnect --force — ends the store's Campaign
 * Intelligence connection: tells the engine (contract §16, Disconnector),
 * then removes the local connection. The engine pauses a store with no
 * connection left and keeps its data; a new setup link resumes it.
 */
class EngineDisconnectCommand extends Command
{
    /** The one-line note per Disconnector outcome; the local connection is removed in every case. */
    private const NOTES = [
        Disconnector::PAUSED => '<info>Campaign Intelligence disconnected. The engine paused the store and keeps'
            . ' its data; connecting again with a new setup link resumes it.</info>',
        Disconnector::CONNECTION_REMOVED => '<info>Campaign Intelligence disconnected. The engine ended this'
            . ' connection; the store\'s other connection keeps working.</info>',
        Disconnector::ENDED => '<info>Campaign Intelligence disconnected. The engine ended this connection.</info>',
        Disconnector::KEY_REFUSED => '<info>Campaign Intelligence disconnected. The engine no longer accepted the'
            . ' key, so the connection had already ended there.</info>',
        Disconnector::NOT_TOLD => '<comment>Campaign Intelligence disconnected here, but the engine could not be'
            . ' told: its key stays valid there until Smaily revokes it.</comment>',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly Disconnector $disconnector
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:engine:disconnect')
            ->setDescription('Disconnect Campaign Intelligence (tells the engine, removes local credentials)')
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

        // The engine is told with the stored key, so before the key is deleted.
        $outcome = $this->disconnector->endConnection(
            $this->settings->getApiKey(),
            $this->settings->getEndpoints(),
            $this->settings->getEngineBaseUrl()
        );
        $this->settings->clear();
        $output->writeln(self::NOTES[$outcome] ?? self::NOTES[Disconnector::ENDED]);

        return Command::SUCCESS;
    }
}
