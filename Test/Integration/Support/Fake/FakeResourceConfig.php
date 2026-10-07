<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\App\ResourceConnection\ConfigInterface;

/**
 * Resource-to-connection mapping: every resource ("default", "checkout",
 * "sales") resolves to the single test database connection — exactly what
 * a stock non-split-database Magento does.
 */
class FakeResourceConfig implements ConfigInterface
{
    /**
     * @inheritDoc
     */
    public function getConnectionName($resourceName)
    {
        return 'default';
    }
}
