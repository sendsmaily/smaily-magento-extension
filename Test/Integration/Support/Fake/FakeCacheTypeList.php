<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\App\Cache\TypeListInterface;

/**
 * No-op cache type list: the harness has no cache to clean (FakeCache
 * misses everything), so a class that cleans the config cache after a
 * config write builds and runs here.
 */
class FakeCacheTypeList implements TypeListInterface
{
    /**
     * @inheritDoc
     * @return array<string, mixed>
     */
    public function getTypes()
    {
        return [];
    }

    /**
     * @inheritDoc
     * @return array<string, string>
     */
    public function getTypeLabels()
    {
        return [];
    }

    /**
     * @inheritDoc
     * @return array<string, mixed>
     */
    public function getInvalidated()
    {
        return [];
    }

    /**
     * @inheritDoc
     * @param string|string[] $typeCode
     */
    public function invalidate($typeCode)
    {
    }

    /**
     * @inheritDoc
     */
    public function cleanType($typeCode)
    {
    }
}
