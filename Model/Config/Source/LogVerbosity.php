<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class LogVerbosity implements OptionSourceInterface
{
    public const ERROR = 'error';
    public const INFO = 'info';
    public const DEBUG = 'debug';

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::ERROR, 'label' => __('Errors only')],
            ['value' => self::INFO, 'label' => __('Info')],
            ['value' => self::DEBUG, 'label' => __('Debug (full API requests and responses)')],
        ];
    }
}
