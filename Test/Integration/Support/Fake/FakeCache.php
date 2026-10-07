<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\App\CacheInterface;

/**
 * No-op application cache: everything is a miss, writes are dropped.
 */
class FakeCache implements CacheInterface
{
    /**
     * @inheritDoc
     */
    public function getFrontend()
    {
        throw new \BadMethodCallException('Cache frontend is not available in the integration test harness');
    }

    /**
     * @param string $identifier
     * @return string|false
     */
    public function load($identifier)
    {
        return false;
    }

    /**
     * @param string $data
     * @param string $identifier
     * @param array<int, string> $tags
     * @param int|null $lifeTime
     * @return bool
     */
    public function save($data, $identifier, $tags = [], $lifeTime = null)
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function remove($identifier)
    {
        return true;
    }

    /**
     * @param array<int, string> $tags
     * @return bool
     */
    public function clean($tags = [])
    {
        return true;
    }
}
