<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Smaily\Connect\Model\Automation\Trigger;

/**
 * The Smaily workflows the engine pushed for the extension's emails
 * (CON-258, as the WooCommerce plugin's CON-173 and CON-218).
 *
 * Each email the extension starts has a design in the engine (contract
 * §22). When the engine has pushed that design to Smaily, its design list
 * (§23) names the Smaily workflow that sends the email and the Smaily
 * account that holds it (`smaily_subdomain`). The extension starts the
 * email with that workflow in place of the picked one, but only when it
 * sends the email through that account — Smaily numbers workflows per
 * account — and in the website's default language
 * (Automation\PushedWorkflow).
 *
 * §23 is read when the email settings open and when the admin comes back
 * from a design page — never before a send (§23). remember() keeps the ids
 * and accounts of the last read in core_config_data
 * (Settings::XML_PATH_EMAIL_WORKFLOWS, JSON `{email_key: {workflow_id,
 * subdomain}}`), and the send path reads only this copy. A setup exchange
 * and a disconnect delete it (Settings). The template id is not kept
 * (contract v1.18.0 makes it informational), and neither is `created_by`:
 * the WooCommerce plugin does not read it either.
 */
class EmailDesignWorkflows
{
    /**
     * Each email the extension can start, by trigger, and its §22 key. The
     * transactional emails are kept too, so the send that Magento does not
     * have yet finds them; only the store-run automations read them today.
     */
    public const EMAIL_KEYS = [
        Trigger::WELCOME => 'plugin.welcome',
        Trigger::FIRST_ORDER => 'plugin.first_order',
        Trigger::ABANDONED_CART => 'plugin.abandoned_cart',
        Trigger::ORDER_CONFIRMATION => 'transactional.order_confirmation',
        Trigger::SHIPMENT_CONFIRMATION => 'transactional.shipping_confirmation',
    ];

    /** §23 kinds of the extension's own emails; an engine email's ids are not the extension's to use. */
    private const KINDS = ['plugin', 'transactional'];

    /** §23 `sent_through` values; both start a Smaily workflow since contract v1.18.0. */
    private const SENT_THROUGH = ['workflow', 'transactional'];

    /**
     * Smaily states whose ids belong to a pushed version. An unknown state
     * counts as `not_available` (§23), whose ids are null.
     */
    private const STATES_WITH_IDS = ['pending', 'pushed', 'failed'];

    /** §23 `smaily_subdomain`: letters, digits and `-`, at most 64 characters. */
    private const SUBDOMAIN_PATTERN = '/^[a-z0-9-]{1,64}$/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly TypeListInterface $cacheTypeList,
        private readonly Json $serializer
    ) {
    }

    /**
     * Keep the workflow id and the account of each email from one §23 read,
     * replacing those of the last one: an email whose id or account is now
     * empty goes back to its picked workflow. An id that names no account
     * is not kept — it fits no account the extension sends through. Writes
     * (and cleans the config cache) only when the kept list changes, so the
     * reads of a store whose ids are all null write nothing.
     *
     * @param array<int|string, mixed> $emails the §23 `emails` list
     */
    public function remember(array $emails): void
    {
        $workflows = [];
        foreach ($emails as $item) {
            if (!is_array($item)
                || !in_array($item['email_key'] ?? null, self::EMAIL_KEYS, true)
                || !in_array($item['kind'] ?? null, self::KINDS, true)
                || !in_array($item['smaily_state'] ?? null, self::STATES_WITH_IDS, true)
                || !in_array($item['sent_through'] ?? null, self::SENT_THROUGH, true)) {
                continue;
            }
            $id = $item['smaily_workflow_id'] ?? null;
            $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int)$id : 0;
            $subdomain = $item['smaily_subdomain'] ?? null;
            $subdomain = is_string($subdomain) ? $this->normaliseSubdomain($subdomain) : '';
            if ($id > 0 && preg_match(self::SUBDOMAIN_PATTERN, $subdomain) === 1) {
                $workflows[(string)$item['email_key']] = [
                    'workflow_id' => $id,
                    'subdomain' => $subdomain,
                ];
            }
        }
        ksort($workflows);

        $stored = $this->stored();
        if ($workflows === $stored) {
            return;
        }
        if ($workflows === []) {
            $this->configWriter->delete(Settings::XML_PATH_EMAIL_WORKFLOWS);
        } else {
            $this->configWriter->save(Settings::XML_PATH_EMAIL_WORKFLOWS, $this->serializer->serialize($workflows));
        }
        $this->cacheTypeList->cleanType(ConfigCache::TYPE_IDENTIFIER);
    }

    /**
     * The pushed workflow id of a trigger's email when the extension sends
     * it through the Smaily account `$subdomain`, or 0: no pushed workflow,
     * or one another account holds.
     */
    public function workflowId(string $trigger, string $subdomain): int
    {
        $entry = $this->stored()[self::EMAIL_KEYS[$trigger] ?? ''] ?? null;
        if ($entry === null || $entry['subdomain'] !== $this->normaliseSubdomain($subdomain)) {
            return 0;
        }

        return $entry['workflow_id'];
    }

    /** A Smaily subdomain's case does not matter (§21, §23): compare it trimmed and lower-case. */
    private function normaliseSubdomain(string $subdomain): string
    {
        return strtolower(trim($subdomain));
    }

    /**
     * The kept list, by email key. An entry that does not have the kept
     * shape is left out.
     *
     * @return array<string, array{workflow_id: int, subdomain: string}>
     */
    private function stored(): array
    {
        $raw = (string)$this->scopeConfig->getValue(Settings::XML_PATH_EMAIL_WORKFLOWS);
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }

        $workflows = [];
        foreach ($decoded as $emailKey => $entry) {
            if (is_string($emailKey)
                && is_array($entry)
                && is_int($entry['workflow_id'] ?? null)
                && $entry['workflow_id'] > 0
                && is_string($entry['subdomain'] ?? null)
                && $entry['subdomain'] !== '') {
                $workflows[$emailKey] = ['workflow_id' => $entry['workflow_id'], 'subdomain' => $entry['subdomain']];
            }
        }
        ksort($workflows);

        return $workflows;
    }
}
