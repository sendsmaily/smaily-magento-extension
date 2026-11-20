<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * A product description as plain text, for the RSS feed and the catalog row
 * Campaign Intelligence gets (CON-358). Page Builder stores a description
 * with a <style> block of its layout rules, and a description can hold
 * Magento's {{widget …}}, {{media url=…}} and other directives, which only
 * the storefront renders: strip_tags() alone left the CSS rules and the
 * directives as text. Style and script blocks go with their content and
 * every {{…}} directive goes; widgets are not rendered.
 */
class DescriptionText
{
    private const BLOCKS_PATTERN = '#<(style|script)\b[^>]*>.*?</\1\s*>#is';
    private const DIRECTIVE_PATTERN = '/\{\{.*?\}\}/s';

    /**
     * The visible text of a description's HTML, trimmed ('' when none).
     */
    public static function fromHtml(string $html): string
    {
        $html = (string)preg_replace(self::BLOCKS_PATTERN, '', $html);
        $html = (string)preg_replace(self::DIRECTIVE_PATTERN, '', $html);

        return trim(strip_tags($html));
    }
}
