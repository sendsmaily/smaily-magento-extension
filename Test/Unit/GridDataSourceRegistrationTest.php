<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Magento merges an area's di.xml over the global one argument by argument:
 * a `collections` argument of the grid CollectionFactory in an area di.xml
 * replaces every grid data source Magento registers globally, and the
 * admin's order, customer and CMS grids fail with "Not registered handle"
 * (CON-434). The module's grid data source is registered in etc/di.xml only.
 */
class GridDataSourceRegistrationTest extends TestCase
{
    private const ETC = __DIR__ . '/../../etc';
    private const COLLECTION_FACTORY = 'Magento\Framework\View\Element\UiComponent\DataProvider\CollectionFactory';

    public function testNoAreaDiXmlRegistersGridCollections(): void
    {
        $areaFiles = glob(self::ETC . '/*/di.xml');
        self::assertNotEmpty($areaFiles);
        foreach ($areaFiles as $file) {
            self::assertSame(
                [],
                $this->registeredCollections($file),
                basename(dirname($file)) . '/di.xml registers grid collections; register them in etc/di.xml'
            );
        }
    }

    public function testGlobalDiXmlRegistersTheLogGridDataSource(): void
    {
        self::assertSame(
            ['smaily_log_grid_data_source' => 'Smaily\Connect\Model\ResourceModel\Log\Collection'],
            $this->registeredCollections(self::ETC . '/di.xml')
        );
    }

    /**
     * @return array<string, string> data source name => collection class
     */
    private function registeredCollections(string $file): array
    {
        $xml = simplexml_load_file($file);
        self::assertNotFalse($xml, $file);
        $collections = [];
        foreach ($xml->xpath('/config/type') ?: [] as $type) {
            if (ltrim((string)$type['name'], '\\') !== self::COLLECTION_FACTORY) {
                continue;
            }
            foreach ($type->xpath('arguments/argument[@name="collections"]/item') ?: [] as $item) {
                $collections[(string)$item['name']] = trim((string)$item);
            }
        }
        return $collections;
    }
}
