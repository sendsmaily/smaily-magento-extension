<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use Smaily\Connect\Setup\Patch\Data\RemoveSettingsOnUninstall;
use Smaily\Connect\Setup\Uninstall;

/**
 * CON-220: the removal tells the engine that the connection ends, so it now
 * has dependencies. docs/INSTALLING.md disables the module before it is
 * uninstalled, and Magento then builds the removal's classes without the
 * module's di.xml (a disabled module's DI configuration is not loaded): a
 * dependency that needs a preference or an argument from it would stop
 * `module:uninstall` before anything is removed. Every Smaily class the
 * removal builds must be concrete and named nowhere in the module's
 * di.xml files; Magento's own types resolve from Magento's configuration.
 */
class UninstallTest extends TestCase
{
    private const DI_FILES = ['etc/di.xml', 'etc/adminhtml/di.xml', 'etc/frontend/di.xml'];

    public function testTheRemovalClassesBuildWithoutTheModulesDiXml(): void
    {
        $diXml = '';
        foreach (self::DI_FILES as $file) {
            $diXml .= (string)file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        }

        $found = [];
        $pending = [Uninstall::class, RemoveSettingsOnUninstall::class];
        $seen = [];
        while ($pending !== []) {
            $class = new ReflectionClass(array_shift($pending));
            if (isset($seen[$class->getName()])) {
                continue;
            }
            $seen[$class->getName()] = true;
            if (!$class->isInstantiable()) {
                $found[] = $class->getName() . ' is not a concrete class';
            }
            if (str_contains($diXml, $class->getName())) {
                $found[] = $class->getName() . ' is configured in di.xml';
            }
            foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    if (!$parameter->isOptional()) {
                        $found[] = $class->getName() . ' $' . $parameter->getName() . ' needs a configured value';
                    }
                    continue;
                }
                if (str_starts_with($type->getName(), 'Smaily\\Connect\\')) {
                    $pending[] = $type->getName();
                }
            }
        }

        self::assertSame([], $found, 'A disabled module cannot be uninstalled with these dependencies');
    }
}
