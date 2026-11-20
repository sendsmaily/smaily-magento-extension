<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model;

/**
 * A product description as plain text, for the RSS feed, the catalog row
 * Campaign Intelligence gets and the abandoned-cart email fields (CON-358).
 * Page Builder stores a description with a <style> block of its layout
 * rules, and a description can hold Magento's {{widget …}}, {{media url=…}}
 * and other directives, which only the storefront renders: strip_tags()
 * alone left the CSS rules and the directives as text. Style and script
 * blocks go with their content and every {{…}} directive goes; widgets are
 * not rendered. Block elements end on a line of their own, so paragraphs do
 * not run together. Entities are decoded, and the result is cleaned and
 * decoded once more, because Page Builder's HTML Code element stores its
 * HTML entity-escaped.
 */
class DescriptionText
{
    private const BLOCKS_PATTERN = '#<(style|script)\b[^>]*>.*?</\1\s*>#is';
    private const DIRECTIVE_PATTERN = '/\{\{.*?\}\}/s';
    private const BLOCK_END_PATTERN =
        '#</(?:p|div|li|h[1-6]|tr|td|th|figure|blockquote|section|article|ul|ol)\s*>|<br\s*/?>#i';

    /**
     * The visible text of a description's HTML, trimmed ('' when none).
     */
    public static function fromHtml(string $html): string
    {
        $text = self::stripMarkup($html);
        $text = self::stripMarkup(self::decode($text));
        $text = self::decode($text);

        // One space for each run of spaces, one line break for each run of
        // lines.
        $text = (string)preg_replace('/[^\S\n]+/u', ' ', $text);
        $text = (string)preg_replace('/ *\n\s*/u', "\n", $text);

        return trim($text);
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function stripMarkup(string $html): string
    {
        $html = (string)preg_replace(self::BLOCKS_PATTERN, '', $html);
        $html = (string)preg_replace(self::DIRECTIVE_PATTERN, '', $html);
        $html = (string)preg_replace(self::BLOCK_END_PATTERN, "$0\n", $html);

        return strip_tags($html);
    }
}
