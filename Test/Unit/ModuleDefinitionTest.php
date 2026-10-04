<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the invariants that keep the package installable both via composer
 * (vendor/smaily/smailyformagento) and manually (app/code/Smaily/Connect).
 */
class ModuleDefinitionTest extends TestCase
{
    private const MODULE_NAME = 'Smaily_Connect';
    private const PACKAGE_ROOT = __DIR__ . '/../..';

    public function testModuleXmlDeclaresModule(): void
    {
        $xml = simplexml_load_file(self::PACKAGE_ROOT . '/etc/module.xml');
        self::assertNotFalse($xml);
        self::assertSame(self::MODULE_NAME, (string)$xml->module['name']);
    }

    /**
     * The module.xml sequence mirrors composer's Magento module requirements
     * exactly: `magento/module-foo-bar` is required if and only if
     * `Magento_FooBar` is sequenced, so Magento loads this module after
     * everything composer installs for it (PRO-2472, PRO-2514).
     */
    public function testModuleSequenceMirrorsComposerModuleRequirements(): void
    {
        $composer = json_decode((string)file_get_contents(self::PACKAGE_ROOT . '/composer.json'), true);
        $required = [];
        foreach (array_keys($composer['require']) as $package) {
            if (preg_match('#^magento/module-(.+)$#', $package, $match) === 1) {
                $required[] = 'Magento_' . str_replace('-', '', ucwords($match[1], '-'));
            }
        }

        $xml = simplexml_load_file(self::PACKAGE_ROOT . '/etc/module.xml');
        self::assertNotFalse($xml);
        $sequenced = [];
        foreach ($xml->module->sequence->module as $module) {
            $sequenced[] = (string)$module['name'];
        }

        sort($required);
        sort($sequenced);
        self::assertNotEmpty($required);
        self::assertSame(
            $required,
            $sequenced,
            'etc/module.xml <sequence> must list exactly the magento/module-* packages composer.json requires'
        );
    }

    public function testRegistrationRegistersModuleAtPackageRoot(): void
    {
        $contents = (string)file_get_contents(self::PACKAGE_ROOT . '/registration.php');
        self::assertStringContainsString("'" . self::MODULE_NAME . "'", $contents);
        self::assertStringContainsString('__DIR__', $contents);
    }

    public function testComposerAutoloadMatchesModuleLayout(): void
    {
        $composer = json_decode((string)file_get_contents(self::PACKAGE_ROOT . '/composer.json'), true);
        self::assertSame('smaily/smailyformagento', $composer['name']);
        self::assertSame('magento2-module', $composer['type']);
        self::assertContains('registration.php', $composer['autoload']['files']);
        // Classes live at the package root so that app/code installs autoload
        // via Magento's module-name-to-path convention.
        self::assertSame('', $composer['autoload']['psr-4']['Smaily\\Connect\\']);
        self::assertArrayHasKey('php', $composer['require']);
        self::assertArrayHasKey('magento/framework', $composer['require']);
    }

    public function testDbSchemaWhitelistCoversAllDeclaredElements(): void
    {
        $xml = simplexml_load_file(self::PACKAGE_ROOT . '/etc/db_schema.xml');
        self::assertNotFalse($xml);
        $whitelist = json_decode(
            (string)file_get_contents(self::PACKAGE_ROOT . '/etc/db_schema_whitelist.json'),
            true
        );

        foreach ($xml->table as $table) {
            $tableName = (string)$table['name'];
            self::assertArrayHasKey($tableName, $whitelist, "Table {$tableName} missing from whitelist");

            foreach ($table->column as $column) {
                $columnName = (string)$column['name'];
                self::assertArrayHasKey(
                    $columnName,
                    $whitelist[$tableName]['column'],
                    "Column {$tableName}.{$columnName} missing from whitelist"
                );
            }
            foreach ($table->constraint as $constraint) {
                $referenceId = (string)$constraint['referenceId'];
                self::assertArrayHasKey(
                    $referenceId,
                    $whitelist[$tableName]['constraint'],
                    "Constraint {$tableName}.{$referenceId} missing from whitelist"
                );
            }
            foreach ($table->index as $index) {
                $referenceId = (string)$index['referenceId'];
                self::assertArrayHasKey(
                    $referenceId,
                    $whitelist[$tableName]['index'],
                    "Index {$tableName}.{$referenceId} missing from whitelist"
                );
            }
        }
    }

    public function testWhitelistHasNoOrphanEntries(): void
    {
        $xml = simplexml_load_file(self::PACKAGE_ROOT . '/etc/db_schema.xml');
        self::assertNotFalse($xml);
        $whitelist = json_decode(
            (string)file_get_contents(self::PACKAGE_ROOT . '/etc/db_schema_whitelist.json'),
            true
        );

        $declared = [];
        foreach ($xml->table as $table) {
            $tableName = (string)$table['name'];
            foreach ($table->column as $column) {
                $declared[$tableName]['column'][(string)$column['name']] = true;
            }
            foreach ($table->constraint as $constraint) {
                $declared[$tableName]['constraint'][(string)$constraint['referenceId']] = true;
            }
            foreach ($table->index as $index) {
                $declared[$tableName]['index'][(string)$index['referenceId']] = true;
            }
        }

        foreach ($whitelist as $tableName => $sections) {
            self::assertArrayHasKey($tableName, $declared, "Whitelist table {$tableName} not in db_schema.xml");
            foreach ($sections as $section => $entries) {
                foreach (array_keys($entries) as $entry) {
                    self::assertTrue(
                        isset($declared[$tableName][$section][$entry]),
                        "Whitelist entry {$tableName}.{$section}.{$entry} not in db_schema.xml"
                    );
                }
            }
        }
    }

    /**
     * Secrets are declared sensitive, so `app:config:dump` never writes them
     * to app/etc/config.php.
     */
    public function testSecretConfigPathsAreDeclaredSensitive(): void
    {
        $xml = simplexml_load_file(self::PACKAGE_ROOT . '/etc/di.xml');
        self::assertNotFalse($xml);
        $items = $xml->xpath(
            "//type[@name='Magento\\Config\\Model\\Config\\TypePool']/arguments"
            . "/argument[@name='sensitive']/item[.='1']/@name"
        );
        $sensitive = array_map('strval', $items ?: []);

        self::assertContains(\Smaily\Connect\Model\Config::XML_PATH_PASSWORD, $sensitive);
        self::assertContains(\Smaily\Connect\Model\Engine\Settings::XML_PATH_API_KEY, $sensitive);
    }
}
