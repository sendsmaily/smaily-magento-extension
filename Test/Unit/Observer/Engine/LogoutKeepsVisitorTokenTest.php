<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Observer\Engine;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * CON-260 (Erkki, 2026-11-02): the visitor token created at a consenting
 * shopper's login stays in the browser after logout, so the storefront can
 * still ask for the shopper's recommendations (contract §15). Nothing in the
 * extension or its Hyvä companion acts on a logout: no observer of a logout
 * event, no plugin on the customer session or the logout pages, and no
 * storefront script that reacts to one. Magento's own logout does not touch
 * the visitor-token cookie.
 */
class LogoutKeepsVisitorTokenTest extends TestCase
{
    private const PACKAGE_ROOT = __DIR__ . '/../../../..';

    public function testNoObserverActsOnALogout(): void
    {
        foreach ($this->files('events.xml') as $file) {
            $xml = simplexml_load_file($file);
            self::assertNotFalse($xml, $file);
            foreach ($xml->event as $event) {
                self::assertStringNotContainsStringIgnoringCase(
                    'logout',
                    (string)$event['name'],
                    $file . ' observes a logout'
                );
            }
        }
    }

    public function testNoPluginWrapsTheCustomerSessionOrTheLogoutPages(): void
    {
        foreach ($this->files('di.xml') as $file) {
            $xml = simplexml_load_file($file);
            self::assertNotFalse($xml, $file);
            foreach ($xml->type as $type) {
                if (!isset($type->plugin)) {
                    continue;
                }
                $name = ltrim((string)$type['name'], '\\');
                self::assertNotSame('Magento\Customer\Model\Session', $name, $file);
                self::assertStringNotContainsStringIgnoringCase('logout', $name, $file);
            }
        }
    }

    public function testNoStorefrontScriptReactsToALogout(): void
    {
        foreach (['view/frontend', 'compat/hyva/view/frontend'] as $dir) {
            foreach ($this->filesUnder($dir) as $file) {
                if (!preg_match('/\.(js|phtml|html)$/', $file)) {
                    continue;
                }
                self::assertStringNotContainsStringIgnoringCase(
                    'logout',
                    (string)file_get_contents($file),
                    $file . ' mentions a logout'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function files(string $basename): array
    {
        $found = [];
        foreach (['etc', 'compat/hyva/etc'] as $dir) {
            foreach ($this->filesUnder($dir) as $file) {
                if (basename($file) === $basename) {
                    $found[] = $file;
                }
            }
        }
        self::assertNotEmpty($found, 'No ' . $basename . ' found');

        return $found;
    }

    /**
     * @return list<string>
     */
    private function filesUnder(string $dir): array
    {
        $path = self::PACKAGE_ROOT . '/' . $dir;
        if (!is_dir($path)) {
            return [];
        }
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
