<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The storefront-only dictionaries (ru_RU, fi_FI, lv_LV, lt_LT) hold every
 * text a shopper can see and nothing else; the admin stays English and
 * Estonian (PRO-4057). The shopper texts are found in the storefront
 * templates, layouts, the Hyvä companion's templates and the storefront
 * controllers, so a new one fails here until the four dictionaries have it.
 */
class StorefrontTranslationsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    private const STOREFRONT_LOCALES = ['ru_RU', 'fi_FI', 'lv_LV', 'lt_LT'];

    private const SOURCES = [
        'view/frontend',
        'compat/hyva/view/frontend',
        'Controller/Cart',
        'Controller/Checkout',
        'Controller/Privacy',
        'Controller/Recommendations',
        'Controller/Relay',
        'Controller/Rss',
    ];

    private const PATTERNS = [
        "/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/",
        "/i18n:\\s*'((?:[^'\\\\]|\\\\.)*)'/",
        "/\\\$t\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/",
        '/translate="true">([^<]+)</',
    ];

    public function testEveryStorefrontDictionaryHoldsExactlyTheShopperTexts(): void
    {
        $shopperTexts = $this->shopperTexts();
        self::assertNotEmpty($shopperTexts);

        foreach (self::STOREFRONT_LOCALES as $locale) {
            $keys = array_keys($this->dictionary($locale));
            sort($keys);
            self::assertSame($shopperTexts, $keys, sprintf('%s.csv does not hold exactly the shopper texts', $locale));
        }
    }

    public function testEveryTranslationKeepsTheEnglishPhraseAndItsPlaceholders(): void
    {
        $english = $this->dictionary('en_US');

        foreach (self::STOREFRONT_LOCALES as $locale) {
            foreach ($this->dictionary($locale) as $phrase => $translation) {
                self::assertArrayHasKey(
                    $phrase,
                    $english,
                    sprintf('%s.csv: "%s" is not in en_US.csv', $locale, $phrase)
                );
                self::assertNotSame('', trim($translation), sprintf('%s.csv: "%s" is empty', $locale, $phrase));
                self::assertNotSame($phrase, $translation, sprintf('%s.csv: "%s" is not translated', $locale, $phrase));
                self::assertSame(
                    $this->placeholders($phrase),
                    $this->placeholders($translation),
                    sprintf('%s.csv: "%s" changes its placeholders', $locale, $phrase)
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function shopperTexts(): array
    {
        $texts = [];
        foreach (self::SOURCES as $source) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $source));
            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $content = (string)file_get_contents($file->getPathname());
                foreach (self::PATTERNS as $pattern) {
                    preg_match_all($pattern, $content, $matches);
                    foreach ($matches[1] as $text) {
                        $texts[stripslashes($text)] = true;
                    }
                }
            }
        }
        $texts = array_map('strval', array_keys($texts));
        sort($texts);

        return $texts;
    }

    /**
     * @return array<string, string>
     */
    private function dictionary(string $locale): array
    {
        $dictionary = [];
        $handle = fopen(self::ROOT . '/i18n/' . $locale . '.csv', 'rb');
        self::assertNotFalse($handle, sprintf('i18n/%s.csv is missing', $locale));
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (isset($row[0], $row[1])) {
                $dictionary[(string)$row[0]] = (string)$row[1];
            }
        }
        fclose($handle);

        return $dictionary;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+/', $text, $matches);
        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }
}
