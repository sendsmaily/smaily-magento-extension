<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

/**
 * A resolved (workflow, account) pair for one automation dispatch.
 *
 * Mirrors the WooCommerce plugin's Smaily\WorkflowMatch: the account key on
 * a mapping row identifies which Smaily credential set must deliver the
 * workflow — in multilingual mode A a fallback row may point at another
 * language's account, and the dispatch has to follow the row, not the
 * event's store view. A null account key means "no row was involved"
 * (config-default workflows): credentials then follow the event's store
 * view as before.
 *
 * The language is the one the workflow was picked for (Woo's
 * matched_language): a row's language ('default' for a language-less row),
 * 'default' for the one workflow of modes single and c, and the contact's
 * language for the workflow mode b falls back to — it serves every language
 * without a workflow of its own. Null: not known. PushedWorkflow reads it:
 * only the website's default language takes a pushed workflow (CON-258).
 */
class WorkflowMatch
{
    public function __construct(
        public readonly int $workflowId,
        public readonly ?string $accountKey = null,
        public readonly ?string $language = null
    ) {
    }
}
