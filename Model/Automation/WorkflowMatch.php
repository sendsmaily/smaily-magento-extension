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
 */
class WorkflowMatch
{
    public function __construct(
        public readonly int $workflowId,
        public readonly ?string $accountKey = null
    ) {
    }
}
