<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Magento's events.xsd allows an event name only once per events.xml. In
 * developer mode Magento validates the file against it, and an event
 * declared twice stops every page and leaves bin/magento without its
 * commands; default and production mode do not validate (CON-437). A
 * second observer for an event goes into the event's one <event> block.
 */
class EventDeclarationTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../..';

    public function testEveryEventIsDeclaredOncePerFile(): void
    {
        $files = array_merge(
            glob(self::PACKAGE_ROOT . '/etc/events.xml') ?: [],
            glob(self::PACKAGE_ROOT . '/etc/*/events.xml') ?: [],
            glob(self::PACKAGE_ROOT . '/compat/*/etc/events.xml') ?: [],
            glob(self::PACKAGE_ROOT . '/compat/*/etc/*/events.xml') ?: []
        );
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $xml = simplexml_load_file($file);
            self::assertNotFalse($xml, $file);
            $names = [];
            foreach ($xml->xpath('/config/event') ?: [] as $event) {
                $names[] = strtolower((string)$event['name']);
            }
            $counts = array_count_values($names);
            self::assertSame(
                [],
                array_keys(array_filter($counts, static fn (int $count): bool => $count > 1)),
                substr($file, strlen(self::PACKAGE_ROOT) + 1) . ' declares an event more than once'
            );
        }
    }
}
