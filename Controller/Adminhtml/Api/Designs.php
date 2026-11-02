<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Api;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Smaily\Connect\Model\Engine\Exception\EngineException;

/**
 * POST (form key) -> {emails: [{email_key, kind, design_state, subject}]}
 *
 * The engine's email designs (contract §23) for the "Edit design" buttons
 * (CON-257): the admin page reads them when it opens, when the admin comes
 * back from a design page and after the design page says it saved or
 * closed. A failure answers {error, message}; the page then shows no
 * button.
 */
class Designs extends AbstractEmailDesignAction implements HttpPostActionInterface
{
    /**
     * @inheritDoc
     */
    public function execute(): Json
    {
        $refusal = $this->refusal();
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            return $this->noStore($this->jsonResponse(['emails' => $this->emailDesigns->designs()]));
        } catch (EngineException $exception) {
            return $this->engineFailure($exception);
        }
    }
}
