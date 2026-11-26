<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Automations;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\DataObject;

/**
 * POST (form key) {email_key, test_mode} -> {ok, message, reasons, state}
 *
 * The "Test mode" switch on a Campaign Intelligence email's card (CON-458,
 * contract §20). `test_mode: false` is sent only after the admin confirmed
 * "Turn off test mode?": it switches real sends on, with the admin's user
 * id as `switched_by` (never an email address or a name). `test_mode:
 * true` switches them off without a question.
 */
class TestMode extends AbstractTestModeAction implements HttpPostActionInterface
{
    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $key = $this->field('email_key');
        $refusal = $this->refusal($key);
        if ($refusal !== null) {
            return $refusal;
        }
        $testMode = ($this->requestBody()['test_mode'] ?? true) !== false;

        return $this->result($this->testMode->switchTestMode($key, $testMode, $this->adminUserId()));
    }

    /** The signed-in admin's user id, or ''. */
    private function adminUserId(): string
    {
        // A DataObject holding `user_id`; Magento_User is not a dependency of this module.
        $user = $this->_auth->getUser();

        return $user instanceof DataObject ? (string)$user->getData('user_id') : '';
    }
}
