<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\ObjectManagerInterface;

/**
 * Creates an engine client bound to one connection's settings (CON-237):
 * every other dependency comes from DI, as for the DI client, which stays
 * the primary connection's.
 */
class ClientFactory
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * @param array{settings: Settings} $data
     */
    public function create(array $data): Client
    {
        return $this->objectManager->create(Client::class, $data);
    }
}
