<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Ui\Component\Listing\Columns\Column;
use Smaily\Connect\Model\Log\EntityLabel;

/**
 * Entity column of the unified log grid: a profiling-consent row shows the
 * short form of the shopper's keyed hash, not 64 hex characters (PRO-3765).
 */
class LogEntityColumn extends Column
{
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
                $item[$name] = EntityLabel::forDisplay((string)($item['type'] ?? ''), (string)($item[$name] ?? ''));
            }
        }

        return $dataSource;
    }
}
