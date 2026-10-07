<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\MessageQueue\PoisonPill\PoisonPillPutInterface;

/**
 * No-op poison pill (config resource dependency; consumers are irrelevant
 * in the integration test harness).
 */
class FakePoisonPillPut implements PoisonPillPutInterface
{
    /**
     * @inheritDoc
     */
    public function put(): string
    {
        return '';
    }
}
