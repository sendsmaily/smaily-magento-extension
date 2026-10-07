<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Support;

use Magento\Framework\Phrase;
use Magento\Framework\Phrase\Renderer\Placeholder;
use Magento\Framework\Phrase\RendererInterface;

/**
 * Makes __() translate with one of the module's own dictionaries, the way
 * Magento does in a request or a cron run with that locale. use() switches
 * it on, reset() back to the untranslated default.
 */
class StoreLocale implements RendererInterface
{
    /**
     * @param array<string, string> $dictionary
     */
    private function __construct(
        private readonly array $dictionary
    ) {
    }

    /**
     * Translate every phrase from now on with i18n/<locale>.csv.
     */
    public static function use(string $locale): void
    {
        $dictionary = [];
        $handle = fopen(dirname(__DIR__, 3) . '/i18n/' . $locale . '.csv', 'rb');
        while ($handle !== false && ($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if (isset($row[0], $row[1])) {
                $dictionary[(string)$row[0]] = (string)$row[1];
            }
        }
        if ($handle !== false) {
            fclose($handle);
        }
        Phrase::setRenderer(new self($dictionary));
    }

    /**
     * Back to the untranslated default.
     */
    public static function reset(): void
    {
        Phrase::setRenderer(new Placeholder());
    }

    /**
     * @param array<int, string> $source
     * @param array<int|string, mixed> $arguments
     */
    public function render(array $source, array $arguments): string
    {
        $text = (string)end($source);

        return (string)(new Placeholder())->render([$this->dictionary[$text] ?? $text], $arguments);
    }
}
