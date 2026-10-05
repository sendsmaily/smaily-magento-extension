<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Controller\Adminhtml\Log;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Block\Template;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\LayoutInterface;
use Smaily\Connect\Model\Engine\Settings as EngineSettings;
use Smaily\Connect\Model\Log\AttemptHistory;
use Smaily\Connect\Model\Log\EntityLabel;
use Smaily\Connect\Model\Log\FailureMessage;
use Smaily\Connect\Model\Log\PayloadRedactor;
use Smaily\Connect\Model\Log\QueueRowLoader;
use Smaily\Connect\Model\Log\Resend;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\Log\StatusPill;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\HandlerPool;
use Smaily\Connect\Model\Queue\PayloadDecoder;
use Smaily\Connect\Model\ResourceModel\Log\Collection;
use Smaily\Connect\Ui\Component\QueueStatusOptions;

/**
 * Per-row drill-down for the unified log grid: renders the delivery detail
 * panel (redacted payload, attempt history, last response) that the grid's
 * Details action loads into a slide-out modal. It also carries what the
 * grid cannot show (PRO-2454): why a row may not be sent again, and — for a
 * row that was — which failed row it repeats, at whose hand.
 *
 * The panel (PRO-3565) heads with the event id and its status pill, lists
 * the attempts in order and ends with "Send again" — offered only for a row
 * the guard clears, so the panel can never double-send — and "Copy payload",
 * which copies the redacted payload the panel shows.
 */
class Details extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Smaily_Connect::event_log';

    public function __construct(
        Context $context,
        private readonly RawFactory $rawFactory,
        private readonly LayoutInterface $layout,
        private readonly QueueRowLoader $rowLoader,
        private readonly PayloadRedactor $redactor,
        private readonly FailureMessage $failureMessage,
        private readonly ResendGuard $resendGuard,
        private readonly Resend $resend,
        private readonly PayloadDecoder $payloadDecoder,
        private readonly QueueStatusOptions $statusOptions,
        private readonly AttemptHistory $attemptHistory,
        private readonly StatusPill $statusPill,
        private readonly UrlInterface $urlBuilder,
        private readonly HandlerPool $handlerPool,
        private readonly EngineSettings $engineSettings
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    public function execute(): Raw
    {
        /** @var Raw $result */
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html; charset=UTF-8', true);

        $logId = (string)$this->getRequest()->getParam('log_id');
        $row = $this->rowLoader->load($logId);
        if ($row === null) {
            $result->setHttpResponseCode(404);
            $row = [];
        }

        $payload = $this->payloadDecoder->decode((string)($row['payload'] ?? ''));
        $reason = $row
            ? $this->resendGuard->refusalReason((string)$row['source'], (int)$row['id'], $row)
            : '';
        $lastError = (string)($row['last_error'] ?? '');

        /** @var Template $block */
        $block = $this->layout->createBlock(Template::class, '', ['data' => [
            'template' => 'Smaily_Connect::log/details.phtml',
            'row' => $row,
            'entity' => EntityLabel::forDisplay((string)($row['type'] ?? ''), (string)($row['entity_id'] ?? '')),
            'redactor' => $this->redactor,
            'payload' => $payload,
            'status_labels' => array_column($this->statusOptions->toOptionArray(), 'label', 'value'),
            // The refusal stands in for a "Send again" the row would
            // otherwise have, so only a failed row shows it — a delivered
            // row that a later delivery superseded reads as delivered
            // (PRO-2511). A withdrawn reminder never failed; its sentence
            // is the drawer's only line for it.
            'refusal' => $reason === ResendGuard::REASON_WITHDRAWN
                || ($reason !== '' && (string)($row['status'] ?? '') === Event::STATUS_FAILED)
                ? $this->resendGuard->message($reason)
                : null,
            'resend_record' => $this->resend->recordOf($payload),
            'last_error' => $this->failureMessage->forDisplay($lastError),
            'failure_class' => $this->failureMessage->failureClass($lastError),
            'status_pill' => $this->statusPill->variant((string)($row['status'] ?? '')),
            'history' => $this->attemptHistory->entries($row),
            'waits_for_account' => $this->waitsForAccount($row),
            // The same rule as the grid's "Send again" action (LogActions):
            // only a row the guard asked about AND cleared.
            'resend_url' => $row && $reason === ''
                ? $this->urlBuilder->getUrl('smaily_connect/log/resend', ['log_id' => $logId])
                : null,
        ]]);

        return $result->setContents($block->toHtml());
    }

    /**
     * Whether a pending row waits for the Campaign Intelligence account to
     * be active again: an engine ingest row while the account is refused
     * (PRO-2451), or a row of a marketing handler paused meanwhile
     * (PRO-2466, PRO-3752). Neither is sent at the next flush.
     *
     * @param array<string, mixed> $row
     */
    private function waitsForAccount(array $row): bool
    {
        if (($row['status'] ?? '') !== Event::STATUS_PENDING) {
            return false;
        }

        return ($row['source'] ?? '') === Collection::SOURCE_INTELLIGENCE
            ? $this->engineSettings->isRefused()
            : in_array((string)($row['type'] ?? ''), $this->handlerPool->pausedEventTypes(), true);
    }
}
