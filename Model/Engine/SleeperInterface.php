<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

/**
 * Test seam for retry backoff delays.
 */
interface SleeperInterface
{
    public function sleep(int $seconds): void;
}
