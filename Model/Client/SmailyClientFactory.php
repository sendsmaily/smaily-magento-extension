<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates scope-bound Smaily API clients.
 */
class SmailyClientFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @param array{subdomain: string, username: string, password: string} $data
     */
    public function create(array $data): SmailyClient
    {
        return $this->objectManager->create(SmailyClient::class, $data);
    }
}
