<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\ModuleInfo;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

/**
 * The bottom of the Dashboard and Settings pages names the installed
 * extension version (CON-235), the one every version cut sets.
 */
class AdminVersionLineTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    protected function tearDown(): void
    {
        StoreLocale::reset();
    }

    public function testTheLineNamesTheModuleVersion(): void
    {
        foreach (['en_US', 'et_EE'] as $locale) {
            StoreLocale::use($locale);

            self::assertSame(
                '<p class="smaily-version">Smaily Connect ' . ModuleInfo::VERSION . '</p>',
                trim($this->render()),
                $locale
            );
        }
    }

    public function testTheVersionIsThePackageVersion(): void
    {
        $composer = json_decode((string)file_get_contents(self::ROOT . '/composer.json'), true);

        self::assertSame($composer['version'], ModuleInfo::VERSION);
    }

    public function testTheDashboardAndSettingsPagesEndWithTheLine(): void
    {
        foreach (['dashboard', 'settings'] as $page) {
            $layout = (string)file_get_contents(
                self::ROOT . '/view/adminhtml/layout/smaily_connect_' . $page . '_index.xml'
            );

            self::assertMatchesRegularExpression(
                '#template="Smaily_Connect::version\.phtml"\s+after="-"/>#',
                $layout,
                $page
            );
        }
    }

    private function render(): string
    {
        $escaper = new Escaper();
        ob_start();
        include self::ROOT . '/view/adminhtml/templates/version.phtml';

        return (string)ob_get_clean();
    }
}
