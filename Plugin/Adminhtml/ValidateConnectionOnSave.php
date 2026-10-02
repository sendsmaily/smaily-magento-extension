<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Adminhtml;

use Magento\Config\Model\Config as SystemConfig;
use Magento\Framework\Message\ManagerInterface;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
use Smaily\Connect\Model\Client\CredentialCheck;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Validates Smaily API credentials when the connection group is saved.
 *
 * NON-BLOCKING by design: the save always succeeds and the validation
 * result is surfaced as an admin message — a merchant must never be unable
 * to save their own configuration. Instant feedback lives in the Test
 * Connection button next to the fields.
 */
class ValidateConnectionOnSave
{
    public function __construct(
        private readonly CredentialCheck $credentialCheck,
        private readonly Config $config,
        private readonly SubdomainNormalizer $normalizer,
        private readonly ManagerInterface $messageManager,
        private readonly Logger $logger
    ) {
    }

    public function beforeSave(SystemConfig $subject): void
    {
        if ($subject->getSection() !== 'smaily_connect') {
            return;
        }

        $groups = (array)$subject->getData('groups');
        $fields = $groups['connection']['fields'] ?? null;
        if (!is_array($fields)) {
            return;
        }

        $storeId = $subject->getStore() !== '' ? $subject->getStore() : null;

        $subdomain = $this->normalizer->normalize(
            $this->resolveValue($fields, 'subdomain') ?? $this->config->getSubdomain($storeId)
        );
        $username = trim($this->resolveValue($fields, 'username') ?? $this->config->getUsername($storeId));
        // A missing or obscured (unchanged) value falls back to the stored password.
        $password = $this->credentialCheck->resolvePassword($this->resolveValue($fields, 'password'), $storeId);

        try {
            if (!$this->credentialCheck->check($subdomain, $username, $password)) {
                return;
            }
            $this->messageManager->addSuccessMessage(
                (string)__('Smaily connection verified — the API credentials work.')
            );
        } catch (PlanBlockedException) {
            // The package, not the credentials (PRO-3579).
            $this->messageManager->addErrorMessage(
                (string)__(
                    'The configuration was saved, but Smaily refused the check because this account\'s package'
                    . ' does not include API access. Synchronization will not work until the account is on a package'
                    . ' that includes it — until then the credentials cannot be checked.'
                )
            );
        } catch (AuthenticationException) {
            $this->messageManager->addErrorMessage(
                (string)__(
                    'The configuration was saved, but Smaily rejected the API credentials.'
                    . ' Check the subdomain, username and password — synchronization will not work until they are correct.'
                )
            );
        } catch (SmailyClientException $exception) {
            $this->logger->info('Skipped credential validation, Smaily API unreachable', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function resolveValue(array $fields, string $field): ?string
    {
        if (!isset($fields[$field]['value'])) {
            return null;
        }

        return (string)$fields[$field]['value'];
    }
}
