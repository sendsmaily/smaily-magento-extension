<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Smaily\Connect\Model\Log\ResendGuard;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Actions column of the unified log grid: a per-row Details action whose
 * href points at the drill-down controller. The column's JS component
 * (Smaily_Connect/js/grid/columns/log-actions) opens the href in a
 * slide-out modal instead of navigating.
 *
 * A failed row also offers "Send again" (PRO-2454) — unless ResendGuard
 * refuses it, in which case the row carries no button and the Details
 * drawer says in one sentence why.
 */
class LogActions extends Column
{
    /**
     * @param array<int|string, mixed> $components
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        private readonly ResendGuard $resendGuard,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @inheritDoc
     *
     * @param array<string, mixed> $dataSource
     * @return array<string, mixed>
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            $name = (string)$this->getData('name');
            $refusals = $this->refusalsOfPage($dataSource['data']['items']);
            foreach ($dataSource['data']['items'] as &$item) {
                if (!isset($item['log_id'])) {
                    continue;
                }
                $logId = (string)$item['log_id'];
                $item[$name]['view'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'smaily_connect/log/details',
                        ['log_id' => $logId]
                    ),
                    'label' => __('Details'),
                ];

                // Only a row the guard was asked about AND cleared gets the
                // action: a malformed log id is not in the map at all.
                if (($refusals[$logId] ?? null) !== '') {
                    continue;
                }

                $item[$name]['resend'] = [
                    'href' => $this->urlBuilder->getUrl(
                        'smaily_connect/log/resend',
                        ['log_id' => $logId]
                    ),
                    'label' => __('Send again'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Send again'),
                        'message' => __(
                            'Send this event again? A new attempt is queued; '
                            . 'the failed row is kept as history.'
                        ),
                    ],
                ];
            }
        }

        return $dataSource;
    }

    /**
     * Why each row of this page may not be sent again — one guard question
     * per queue for the whole page, rather than one per row.
     *
     * @param array<int|string, array<string, mixed>> $items
     * @return array<string, string> refusal reason ('' when none) by log id
     */
    private function refusalsOfPage(array $items): array
    {
        $rows = [Collection::SOURCE_SMAILY => [], Collection::SOURCE_INTELLIGENCE => []];
        foreach ($items as $item) {
            [$source, $id] = Collection::splitLogId((string)($item['log_id'] ?? ''));
            if ($source !== '') {
                $rows[$source][$id] = $item;
            }
        }

        $refusals = [];
        foreach ($rows as $source => $sourceRows) {
            $reasons = $this->resendGuard->refusalReasons($source, $sourceRows);
            foreach (array_keys($sourceRows) as $id) {
                $refusals[$source . '-' . $id] = $reasons[$id] ?? '';
            }
        }

        return $refusals;
    }
}
