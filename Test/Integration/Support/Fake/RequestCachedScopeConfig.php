<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * DatabaseScopeConfig as Magento's scope config behaves in one request: the
 * first read loads core_config_data of every scope, and later reads use
 * that copy until `clear()` — a value written in the request is read back
 * as it was before the write.
 */
class RequestCachedScopeConfig extends DatabaseScopeConfig
{
    /**
     * @var array<string, string>|null scope|scope id|path => value
     */
    private ?array $rows = null;

    /**
     * @param array<int, int> $storeWebsites store view id => website id
     */
    public function __construct(
        private readonly AdapterInterface $database,
        array $storeWebsites
    ) {
        parent::__construct($database, $storeWebsites);
    }

    /**
     * The request ends: the next read loads the saved values.
     */
    public function clear(): void
    {
        $this->rows = null;
    }

    protected function row(string $scope, int $scopeId, string $path): ?string
    {
        if ($this->rows === null) {
            $this->rows = [];
            $select = $this->database->select()->from('core_config_data', ['scope', 'scope_id', 'path', 'value']);
            foreach ($this->database->fetchAll($select) as $row) {
                $this->rows[$row['scope'] . '|' . $row['scope_id'] . '|' . $row['path']] = (string)$row['value'];
            }
        }

        return $this->rows[$scope . '|' . $scopeId . '|' . $path] ?? null;
    }
}
