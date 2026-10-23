<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps the module compilable on Magento 2.4.4, the oldest supported
 * version (CON-204). Its `setup:di:compile` reads every class's
 * constructor and copies the method signatures of every class it makes an
 * interceptor for, and both of its readers call ReflectionNamedType
 * methods on each type: a union or intersection type stops the compile
 * ("Call to undefined method ReflectionUnionType::getName()"), and a
 * `mixed` type becomes `?mixed`, which PHP cannot parse. Newer versions
 * read these types (2.4.5 still not in an interceptor's return type;
 * 2.4.8 reads all of them), so a newer test store does not show it.
 *
 * Interceptors are made for the classes Magento's plugins apply to, which
 * includes every controller and most classes that extend a Magento class;
 * the rule covers every class that extends or implements a Magento type.
 */
class GeneratedCodeCompatibilityTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../..';

    /** The module's class directories. */
    private const CLASS_DIRS = [
        'Api', 'Block', 'Console', 'Controller', 'Cron', 'Model', 'Observer', 'Plugin', 'Setup', 'Ui', 'ViewModel',
    ];

    public function testNoConstructorParameterHasAUnionOrIntersectionType(): void
    {
        $found = [];
        foreach ($this->moduleClasses() as $class) {
            $constructor = $class->getConstructor();
            if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            foreach ($constructor->getParameters() as $parameter) {
                if ($this->isComposite($parameter->getType())) {
                    $found[] = $class->getName() . '::__construct() $' . $parameter->getName()
                        . ' ' . $parameter->getType();
                }
            }
        }

        self::assertSame([], $found, 'Magento 2.4.4 cannot compile a constructor parameter with these types');
    }

    public function testClassesMagentoMayInterceptHaveOnlySingleNamedTypes(): void
    {
        $found = [];
        foreach ($this->moduleClasses() as $class) {
            if (!$this->extendsMagentoType($class)) {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName()
                    || $method->isPrivate() || $method->isStatic() || $method->isFinal()
                    || $method->isConstructor()
                ) {
                    continue;
                }
                $types = ['return' => $method->getReturnType()];
                foreach ($method->getParameters() as $parameter) {
                    $types['$' . $parameter->getName()] = $parameter->getType();
                }
                foreach ($types as $what => $type) {
                    if ($this->isComposite($type) || $this->isMixed($type)) {
                        $found[] = $class->getName() . '::' . $method->getName() . '() ' . $what . ' ' . $type;
                    }
                }
            }
        }

        self::assertSame([], $found, 'Magento 2.4.4 cannot generate an interceptor for a method with these types');
    }

    /**
     * Guards the guard: the rule sees a union type where Magento 2.4.4 fails.
     */
    public function testTheRuleRecognisesTheTypesMagento244CannotRead(): void
    {
        $union = (new \ReflectionFunction(static fn (int|string $value): int|string => $value))->getReturnType();
        $mixed = (new \ReflectionFunction(static fn (mixed $value): mixed => $value))->getReturnType();
        $single = (new \ReflectionFunction(static fn (?int $value): ?int => $value))->getReturnType();

        self::assertTrue($this->isComposite($union));
        self::assertTrue($this->isMixed($mixed));
        self::assertFalse($this->isComposite($single) || $this->isMixed($single));
    }

    /**
     * @return list<\ReflectionClass<object>>
     */
    private function moduleClasses(): array
    {
        $classes = [];
        foreach (self::CLASS_DIRS as $dir) {
            $path = self::PACKAGE_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr((string)$file->getRealPath(), strlen((string)realpath(self::PACKAGE_ROOT)) + 1);
                $name = 'Smaily\\Connect\\' . str_replace(['/', '.php'], ['\\', ''], $relative);
                if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
                    $classes[] = new \ReflectionClass($name);
                }
            }
        }
        self::assertNotEmpty($classes);

        return $classes;
    }

    /**
     * @param \ReflectionClass<object> $class
     */
    private function extendsMagentoType(\ReflectionClass $class): bool
    {
        $parents = array_merge(
            $class->getInterfaceNames(),
            array_map(static fn (\ReflectionClass $parent): string => $parent->getName(), $this->parents($class))
        );
        foreach ($parents as $parent) {
            if (str_starts_with($parent, 'Magento\\')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \ReflectionClass<object> $class
     * @return list<\ReflectionClass<object>>
     */
    private function parents(\ReflectionClass $class): array
    {
        $parents = [];
        while (($class = $class->getParentClass()) !== false) {
            $parents[] = $class;
        }

        return $parents;
    }

    private function isComposite(?\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType;
    }

    private function isMixed(?\ReflectionType $type): bool
    {
        return $type instanceof \ReflectionNamedType && $type->getName() === 'mixed';
    }
}
