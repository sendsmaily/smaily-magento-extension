<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Array-backed scope config: tests set values directly, scope is ignored
 * (the classes under test here only read default-scope values).
 */
class FakeScopeConfig implements ScopeConfigInterface
{
    /**
     * @var array<string, mixed>
     */
    private $values = [];

    /**
     * Set a config value for subsequent reads.
     *
     * @param string $path
     * @param mixed $value
     */
    public function setValue(string $path, $value): void
    {
        $this->values[$path] = $value;
    }

    /**
     * Drop all stored values (test isolation).
     */
    public function reset(): void
    {
        $this->values = [];
    }

    /**
     * @inheritDoc
     */
    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return $this->values[$path] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool)($this->values[$path] ?? false);
    }
}
