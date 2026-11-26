<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Automations;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;

/**
 * POST (form key) {email_key, email} -> {ok, message, reasons, state}
 *
 * "Send test email" on a Campaign Intelligence email's card (CON-458,
 * contract §19): one test email to the one address the admin typed — by
 * default the admin's own. The address joins the store's Smaily list as a
 * test contact; the extension keeps no copy of it. One press, one call:
 * a refusal is never retried.
 */
class TestSend extends AbstractTestModeAction implements HttpPostActionInterface
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

        return $this->result($this->testMode->testSend($key, $this->field('email')));
    }
}
