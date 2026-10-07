<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

class Sleeper implements SleeperInterface
{
    /**
     * @inheritDoc
     */
    public function sleep(int $seconds): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged
        sleep(max(0, $seconds));
    }
}
