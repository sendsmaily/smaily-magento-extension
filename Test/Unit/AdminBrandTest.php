<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every extension page draws the Smaily Connect logo above its title, and
 * only the Dashboard, the extension's landing page, plays its entrance
 * (CON-372). Test/Js/brand-logos.html checks the logo and the entrance.
 */
class AdminBrandTest extends TestCase
{
    private const LAYOUTS = __DIR__ . '/../../view/adminhtml/layout/smaily_connect_%s_index.xml';

    public function testEveryPageDrawsTheLogoAboveItsTitleAndOnlyTheDashboardPlaysTheEntrance(): void
    {
        foreach (['dashboard' => true, 'wizard' => false, 'settings' => false, 'log' => false] as $page => $entrance) {
            $layout = simplexml_load_file(sprintf(self::LAYOUTS, $page));
            self::assertNotFalse($layout, $page);

            $blocks = $layout->xpath('//referenceContainer[@name="main.top"]/block[@name="smaily.brand"]');
            self::assertCount(1, $blocks, $page);
            self::assertSame('Smaily_Connect::brand.phtml', (string)$blocks[0]['template'], $page);
            self::assertSame('page.title', (string)$blocks[0]['before'], $page);
            self::assertSame(
                $entrance ? ['true'] : [],
                array_map('strval', $blocks[0]->xpath('arguments/argument[@name="entrance"]')),
                $page
            );
        }
    }
}
