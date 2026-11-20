<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\DescriptionText;

/**
 * CON-358: a description Page Builder saved reaches the feed and the
 * catalog row as its visible text only — no CSS rules from its <style>
 * block, no {{widget …}} or {{media url=…}} directive.
 */
class DescriptionTextTest extends TestCase
{
    /**
     * A description as Page Builder stores it: its layout rules in a <style>
     * block, a row with a Text element (its paragraphs on lines of their own,
     * as the editor writes them) holding a product list widget and a media
     * directive, and an Image element.
     */
    public const PAGE_BUILDER_HTML = '<style>#html-body [data-pb-style=K8M5Q2X]{justify-content:flex-start;'
        . 'display:flex;flex-direction:column;background-position:left top;background-size:cover;'
        . 'background-repeat:no-repeat;background-attachment:scroll}'
        . '#html-body [data-pb-style=V3N7R1A]{border-style:none}</style>'
        . '<div data-content-type="row" data-appearance="contained" data-element="main">'
        . '<div data-enable-parallax="0" data-parallax-speed="0.5" data-background-images="{}" '
        . 'data-background-type="image" data-video-loop="true" data-video-play-only-visible="true" '
        . 'data-video-lazy-load="true" data-video-fallback-src="" data-element="inner" data-pb-style="K8M5Q2X">'
        . '<div data-content-type="text" data-appearance="default" data-element="main">'
        . "<p>A roomy canvas bag for every trip.</p>\n"
        . '<p>{{widget type="Magento\CatalogWidget\Block\Product\ProductsList" show_pager="0" '
        . 'products_count="5" template="Magento_CatalogWidget::product/widget/content/grid.phtml" '
        . 'conditions_encoded="^[`1`:^[`type`:`Magento||CatalogWidget||Model||Rule||Condition||Combine`,'
        . '`aggregator`:`all`,`value`:`1`,`new_child`:``^]^]"}}</p>' . "\n"
        . '<p>Pattern: {{media url="wysiwyg/pattern.jpg"}}</p></div>'
        . '<figure data-content-type="image" data-appearance="full-width" data-element="main" '
        . 'data-pb-style="V3N7R1A"><img class="pagebuilder-mobile-hidden" src="{{media url=wysiwyg/bag.jpg}}" '
        . 'alt="" title="" data-element="desktop_image"></figure></div></div>';

    public function testAPageBuilderDescriptionKeepsOnlyItsText(): void
    {
        self::assertSame(
            "A roomy canvas bag for every trip.\n\nPattern:",
            DescriptionText::fromHtml(self::PAGE_BUILDER_HTML)
        );
    }

    public function testAScriptBlockGoesWithItsContent(): void
    {
        self::assertSame(
            'Oak table',
            DescriptionText::fromHtml('<script type="text/x-magento-init">{"*": {"x": {}}}</script><p>Oak table</p>')
        );
    }

    public function testPlainHtmlKeepsItsText(): void
    {
        self::assertSame('Solid oak, 2 m long.', DescriptionText::fromHtml("<p>Solid <b>oak</b>, 2 m long.</p>\n"));
    }

    public function testADescriptionOfOnlyMarkupIsEmpty(): void
    {
        self::assertSame('', DescriptionText::fromHtml('<style>.a{color:red}</style><p>{{widget type="X"}}</p>'));
    }
}
