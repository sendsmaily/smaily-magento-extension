<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

use Smaily\Connect\Model\Engine\EmailDesignWorkflows;

/**
 * The workflow an email of the extension starts with: the one Campaign
 * Intelligence pushed to Smaily, in place of the one the merchant picked,
 * when two things hold (CON-258; the WooCommerce plugin's
 * Multilingual\Router::resolve_workflow(), CON-173 and CON-218):
 *
 * - The Smaily account that sends the email holds the pushed workflow: its
 *   subdomain is the one the engine names for it (§23 `smaily_subdomain`,
 *   trimmed, case-insensitive). Smaily numbers workflows per account, so on
 *   another account the id would fail or start another workflow.
 * - The picked workflow is the website's default language's: the one
 *   workflow of modes single and c, a language-less row, or the workflow
 *   picked for the language of the website's default store view (a fallback
 *   to it counts — the shopper gets the default-language email anyway). The
 *   engine pushes one design per email and does not say that it covers
 *   every language, so every other language keeps its picked workflow.
 *
 * Everything else stays the picked one: whether the email goes at all, its
 * account and its language. With no pushed workflow (the engine sends null
 * ids until it announces the push) nothing changes. Nothing here asks the
 * engine: the ids come from the last §23 read (EmailDesignWorkflows).
 *
 * Magento picks the sending account after the Router (AutomationHandler::
 * clientFor()), so the handler asks here with that account's subdomain and
 * the website's default language (AccountResolver::defaultLanguage()); the
 * WooCommerce plugin decides it inside its router. The transactional emails
 * can ask the same way once Magento sends them.
 */
class PushedWorkflow
{
    public function __construct(
        private readonly EmailDesignWorkflows $workflows
    ) {
    }

    /**
     * The workflow id to start `$trigger`'s email with, sent through the
     * Smaily account `$subdomain` on a website whose default language is
     * `$defaultLanguage` ('' when it has none).
     */
    public function workflowId(string $trigger, WorkflowMatch $match, string $subdomain, string $defaultLanguage): int
    {
        $pushed = $this->workflows->workflowId($trigger, $subdomain);
        if ($pushed <= 0 || !$this->isDefaultLanguage($match, $defaultLanguage)) {
            return $match->workflowId;
        }

        return $pushed;
    }

    private function isDefaultLanguage(WorkflowMatch $match, string $defaultLanguage): bool
    {
        if ($match->language === null || $match->language === '') {
            return false;
        }

        return $match->language === Mapping::LANGUAGE_DEFAULT
            || ($defaultLanguage !== '' && $match->language === $defaultLanguage);
    }
}
