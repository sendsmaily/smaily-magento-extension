<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Logger;

use Psr\Log\LoggerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\LogVerbosity;

/**
 * Verbosity-gated logger writing to var/log/smaily_connect.log.
 *
 * Errors are always logged; info and debug respect the configured verbosity
 * so busy stores are not flooded (legacy extension issue #113).
 *
 * Every email address in the message and in the context's text is masked
 * (EmailMask) before it is written: the file sits on the server outside the
 * admin's roles, and an error text that Smaily or Campaign Intelligence sends
 * back can quote a contact's address.
 */
class Logger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Config $config
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->logger->error(EmailMask::apply($message), $this->mask($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        if (in_array($this->config->getLogVerbosity(), [LogVerbosity::INFO, LogVerbosity::DEBUG], true)) {
            $this->logger->info(EmailMask::apply($message), $this->mask($context));
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if ($this->config->getLogVerbosity() === LogVerbosity::DEBUG) {
            $this->logger->debug(EmailMask::apply($message), $this->mask($context));
        }
    }

    /**
     * The context with every address in its text masked, at any depth.
     *
     * @param array<int|string, mixed> $context
     * @return array<int|string, mixed>
     */
    private function mask(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = EmailMask::apply($value);
            } elseif (is_array($value)) {
                $context[$key] = $this->mask($value);
            }
        }

        return $context;
    }
}
