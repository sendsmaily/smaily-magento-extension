<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Scope config read from the test database's core_config_data with
 * Magento's fallback: store view -> its website -> default scope -> the
 * module's etc/config.xml default. The store-to-website map is the store
 * layout the test describes (there is no store table in the harness).
 */
class DatabaseScopeConfig implements ScopeConfigInterface
{
    /**
     * @var array<string, string>|null
     */
    private ?array $moduleDefaults = null;

    /**
     * @param array<int, int> $storeWebsites store view id => website id
     */
    public function __construct(
        private readonly AdapterInterface $connection,
        private readonly array $storeWebsites
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getValue($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        $scopeId = (int)$scopeCode;
        switch ($scopeType) {
            case 'store':
            case 'stores':
                return $this->row('stores', $scopeId, $path)
                    ?? $this->getValue($path, 'websites', $this->storeWebsites[$scopeId] ?? 0);
            case 'website':
            case 'websites':
                return $this->row('websites', $scopeId, $path) ?? $this->getValue($path);
            default:
                return $this->row('default', 0, $path) ?? $this->moduleDefaults()[$path] ?? null;
        }
    }

    /**
     * @inheritDoc
     */
    public function isSetFlag($path, $scopeType = ScopeConfigInterface::SCOPE_TYPE_DEFAULT, $scopeCode = null)
    {
        return (bool)$this->getValue($path, $scopeType, $scopeCode);
    }

    protected function row(string $scope, int $scopeId, string $path): ?string
    {
        $values = $this->connection->fetchCol(
            $this->connection->select()->from('core_config_data', ['value'])
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
                ->where('path = ?', $path)
        );

        return isset($values[0]) ? (string)$values[0] : null;
    }

    /**
     * @return array<string, string>
     */
    private function moduleDefaults(): array
    {
        if ($this->moduleDefaults === null) {
            $this->moduleDefaults = [];
            $xml = simplexml_load_file(__DIR__ . '/../../../../etc/config.xml');
            foreach ($xml->default->children() as $section) {
                foreach ($section->children() as $group) {
                    foreach ($group->children() as $field) {
                        $path = $section->getName() . '/' . $group->getName() . '/' . $field->getName();
                        $this->moduleDefaults[$path] = (string)$field;
                    }
                }
            }
        }

        return $this->moduleDefaults;
    }
}
