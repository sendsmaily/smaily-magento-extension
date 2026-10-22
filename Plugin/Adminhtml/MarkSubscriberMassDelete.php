<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Plugin\Adminhtml;

use Magento\Newsletter\Controller\Adminhtml\Subscriber\MassDelete;
use Smaily\Connect\Model\ContactSync\AdminSubscriberDelete;

/**
 * Runs the Newsletter Subscribers grid's Delete action under the
 * AdminSubscriberDelete mark (CON-176), so each row it deletes unsubscribes
 * the contact in Smaily (Observer\SubscriberDeleteAfter). The grid has no
 * other delete: deleting one row is this action with one row selected.
 */
class MarkSubscriberMassDelete
{
    public function __construct(
        private readonly AdminSubscriberDelete $adminSubscriberDelete
    ) {
    }

    /**
     * @param MassDelete $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundExecute(MassDelete $subject, callable $proceed): mixed
    {
        return $this->adminSubscriberDelete->run($proceed);
    }
}
