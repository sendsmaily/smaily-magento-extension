<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;
use Smaily\Connect\Model\Log\StatusPill;

/**
 * Status column of the unified log grid (PRO-3565): each row carries the
 * pill variant its status is drawn with, in `<column>_pill`. The column's JS
 * component (Smaily_Connect/js/grid/columns/status-pill) draws the label of
 * the status option inside that pill; filtering and sorting stay the stock
 * select column's.
 */
class LogStatusColumn extends Column
{
    /**
     * @param array<int|string, mixed> $components
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly StatusPill $statusPill,
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
            foreach ($dataSource['data']['items'] as &$item) {
                $item[$name . '_pill'] = $this->statusPill->variant((string)($item[$name] ?? ''));
            }
        }

        return $dataSource;
    }
}
