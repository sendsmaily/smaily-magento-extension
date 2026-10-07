<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Multilingual workflow routing modes (aligned with the WooCommerce plugin's
 * Multilingual\Router: SINGLE/A/B/C).
 */
class MultilingualMode implements OptionSourceInterface
{
    public const MODE_SINGLE = 'single';
    public const MODE_PER_LANGUAGE_ACCOUNTS = 'a';
    public const MODE_PER_LANGUAGE_WORKFLOWS = 'b';
    public const MODE_SINGLE_WORKFLOW = 'c';

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_SINGLE, 'label' => __('Single language')],
            ['value' => self::MODE_PER_LANGUAGE_ACCOUNTS, 'label' => __('Per-language Smaily accounts')],
            ['value' => self::MODE_PER_LANGUAGE_WORKFLOWS, 'label' => __('One account, per-language workflows')],
            ['value' => self::MODE_SINGLE_WORKFLOW, 'label' => __('One workflow branching by language')],
        ];
    }
}
