<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates queue event models.
 */
class EventFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): Event
    {
        return $this->objectManager->create(Event::class, $data);
    }
}
