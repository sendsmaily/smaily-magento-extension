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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento smaily:engine:ping — Campaign Intelligence health check.
 */
class EnginePingCommand extends Command
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client
    ) {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    protected function configure(): void
    {
        $this->setName('smaily:engine:ping')
            ->setDescription('Check the Campaign Intelligence engine connection');
    }

    /**
     * @inheritDoc
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->settings->isConnected()) {
            $output->writeln('<comment>Campaign Intelligence is not connected.</comment>');

            return Command::FAILURE;
        }

        try {
            $response = $this->client->ping();
        } catch (EngineException $exception) {
            $output->writeln(sprintf('<error>Ping failed: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            '<info>Connected. Tenant: %s, engine version: %s, ping: %s</info>',
            $this->settings->getTenantName() ?: $this->settings->getTenantId(),
            $this->settings->getEngineVersion() ?: '?',
            json_encode($response)
        ));

        return Command::SUCCESS;
    }
}
