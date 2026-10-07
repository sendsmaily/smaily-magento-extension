<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Ui\Component;

use Magento\Framework\Data\OptionSourceInterface;
use Smaily\Connect\Model\ResourceModel\Log\Collection;

/**
 * Source filter options for the unified log grid.
 */
class LogSourceOptions implements OptionSourceInterface
{
    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Collection::SOURCE_SMAILY, 'label' => __('Smaily')],
            ['value' => Collection::SOURCE_INTELLIGENCE, 'label' => __('Campaign Intelligence')],
        ];
    }
}
