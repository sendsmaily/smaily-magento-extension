<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Queue;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates ingest event models.
 */
class IngestEventFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): IngestEvent
    {
        return $this->objectManager->create(IngestEvent::class, $data);
    }
}
