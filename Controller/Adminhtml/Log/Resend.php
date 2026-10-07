<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend as ResendModel;
use Smaily\Connect\Model\Log\ResendGuard;

/**
 * Sends one failed log row again: a NEW queue row carrying the same event,
 * with the failed row kept untouched as history (PRO-2454). The guard has
 * the last word here too — the grid hides the action on a row it refuses,
 * but a stale page must not be able to double-send.
 */
class Resend extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly AuthSession $authSession,
        private readonly QueueRowLoader $rowLoader,
        private readonly ResendGuard $resendGuard,
        private readonly ResendModel $resend
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Redirect
    {
        $logId = (string)$this->getRequest()->getParam('log_id');
        $row = $this->rowLoader->load($logId);

        if ($row === null) {
            $this->messageManager->addErrorMessage(
                (string)__('This log row no longer exists — it has likely been pruned by retention.')
            );

            return $this->backToLog();
        }

        $reason = $this->resendGuard->refusalReason((string)$row['source'], (int)$row['id'], $row);
        if ($reason !== '') {
            $this->messageManager->addErrorMessage((string)$this->resendGuard->message($reason));

            return $this->backToLog();
        }

        $user = $this->authSession->getUser();
        $queued = $this->resend->resend($row, $user === null ? '' : (string)$user->getUserName());

        if ($queued) {
            $this->messageManager->addSuccessMessage((string)__('Event queued to be sent again.'));
        } else {
            $this->messageManager->addErrorMessage(
                (string)__('The event could not be queued again — please try again.')
            );
        }

        return $this->backToLog();
    }

    private function backToLog(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $redirect->setPath('smaily_connect/log');
    }
}
