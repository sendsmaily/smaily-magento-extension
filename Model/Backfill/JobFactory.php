<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Backfill;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates backfill job models.
 */
class JobFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data = []): Job
    {
        return $this->objectManager->create(Job::class, $data);
    }
}
