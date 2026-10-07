<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer;

use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\OrderOrigin;
use Smaily\Connect\Observer\RecordOrderOrigin;

/**
 * PRO-3660: every placed order is stamped by origin, and a failure to stamp
 * it never stops the order.
 */
class RecordOrderOriginTest extends TestCase
{
    public function testAPlacedOrderIsRecorded(): void
    {
        $orderOrigin = $this->createMock(OrderOrigin::class);
        $orderOrigin->expects(self::once())->method('record');

        (new RecordOrderOrigin($orderOrigin, $this->createMock(Logger::class)))->execute(new Observer());
    }

    public function testAFailureIsLoggedAndTheOrderGoesOn(): void
    {
        $orderOrigin = $this->createMock(OrderOrigin::class);
        $orderOrigin->method('record')->willThrowException(new \RuntimeException('database gone'));
        $logger = $this->createMock(Logger::class);
        $logger->expects(self::once())->method('error');

        (new RecordOrderOrigin($orderOrigin, $logger))->execute(new Observer());
    }
}
