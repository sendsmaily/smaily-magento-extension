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
use Smaily\Connect\Model\Log\FailureMessage;

/**
 * Last Error column of the unified log grid: the merchant reads the
 * server's own message, redacted exactly like the Details drawer beside it
 * (PRO-2454). The internal failure class stays in the drawer.
 */
class LogErrorColumn extends Column
{
    /**
     * @param array<int|string, mixed> $components
     * @param array<int|string, mixed> $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly FailureMessage $failureMessage,
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
                $item[$name] = $this->failureMessage->forDisplay(
                    isset($item[$name]) ? (string)$item[$name] : null
                );
            }
        }

        return $dataSource;
    }
}
