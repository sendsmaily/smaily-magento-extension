<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\Event\ManagerInterface;

/**
 * No-op event manager: model save events are irrelevant to these tests.
 */
class FakeEventManager implements ManagerInterface
{
    /**
     * @param string $eventName
     * @param array<string, mixed> $data
     * @return void
     */
    public function dispatch($eventName, array $data = [])
    {
    }
}
