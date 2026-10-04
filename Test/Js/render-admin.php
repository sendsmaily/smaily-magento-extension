<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

/*
 * Renders the admin templates the browser harnesses under Test/Js drive
 * (PRO-3736) into Test/Js/build/<page>.<locale>.js, in en_US and et_EE, as
 * Magento renders them: the real template, Magento's Escaper, and __()
 * translated with i18n/<locale>.csv. Each page's view model is fixed data,
 * so nothing reads Magento, Smaily or the engine. bin/test-js.sh runs this
 * first; a harness page loads the file with a <script> tag and finds
 * window.smailyAdminPages['<page>.<locale>'] = {html, strings}, where strings
 * holds the page's phrases in that locale for the checks.
 *
 * To add a screen: add an entry to $pages below.
 */

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

/**
 * The template's $block: the view model and an admin URL.
 */
$block = static fn (object $viewModel): object => new class ($viewModel) {
    /**
     * @param object $viewModel
     */
    public function __construct(private readonly object $viewModel)
    {
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getData(string $key)
    {
        return $key === 'view_model' ? $this->viewModel : null;
    }

    /**
     * @param string $route
     * @return string
     */
    public function getUrl(string $route): string
    {
        return 'https://store.example/admin/' . $route . '/';
    }
};

// Magento's Escaper without an object manager: its two helpers set directly.
$escaper = new Escaper();
(new ReflectionProperty(Escaper::class, 'escaper'))->setValue($escaper, new ZendEscaper());
(new ReflectionProperty(Escaper::class, 'translateInline'))->setValue($escaper, new class implements InlineInterface {
    /**
     * @param string|null $tagName
     * @return string
     */
    public function getAdditionalHtmlAttribute($tagName = null)
    {
        return '';
    }

    /**
     * @return bool
     */
    public function isAllowed()
    {
        return false;
    }

    /**
     * @param array<mixed>|string $body
     * @param bool $isJson
     * @return $this
     */
    public function processResponseBody(&$body, $isJson = false)
    {
        return $this;
    }

    /**
     * @return \Magento\Framework\Translate\Inline\ParserInterface
     */
    public function getParser()
    {
        throw new LogicException('The admin pages are rendered without inline translation.');
    }
});

/*
 * The Automations tab (view/adminhtml/templates/config/engine-automations.phtml)
 * with Campaign Intelligence connected and three triggers as the page loads
 * them: Replenishment due and Win-back off in test mode (the fail-closed
 * defaults), Post-purchase off but stored with test mode off — it sent to
 * real customers before, so the engine keeps real sends on when it is
 * switched on again (contract §13).
 */
$trigger = static fn (string $key, string $name, bool $testMode): array => [
    'key' => $key,
    'name' => $name,
    'description' => '',
    'recipe' => '',
    'enabled' => false,
    'workflow_id' => '',
    'language_mode' => 'single',
    'original_map' => '{}',
    'cooldown_days' => 7,
    'daily_cap' => null,
    'test_mode' => $testMode,
    'test_emails' => '',
];
$pages = [
    'automations' => [
        'template' => $root . '/view/adminhtml/templates/config/engine-automations.phtml',
        'viewModel' => new class ([
            $trigger('replenish_due', 'Replenishment due', true),
            $trigger('post_purchase', 'Post-purchase', false),
            $trigger('winback_risk', 'Win-back', true),
        ]) {
            /**
             * @param array<int, array<string, mixed>> $rows
             */
            public function __construct(private readonly array $rows)
            {
            }

            /**
             * @return bool
             */
            public function isEngineConnected(): bool
            {
                return true;
            }

            /**
             * @return null
             */
            public function getLoadError()
            {
                return null;
            }

            /**
             * @return array<int, array{id: int, title: string}>
             */
            public function getWorkflows(): array
            {
                return [['id' => 101, 'title' => 'Replenishment'], ['id' => 102, 'title' => 'Thank you']];
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            public function getRows(): array
            {
                return $this->rows;
            }
        },
        'strings' => [
            'Off',
            'Test mode',
            'Active',
            'Saved.',
            'Saved. Reload the page to see the state of each trigger.',
            'Smaily switches real sends on after you confirm. Until then the trigger runs in test mode.',
        ],
    ],
];

$render = static function (string $template, object $block, Escaper $escaper): string {
    ob_start();
    include $template;

    return (string)ob_get_clean();
};

$build = __DIR__ . '/build';
if (!is_dir($build)) {
    mkdir($build); // phpcs:ignore Magento2.Functions.DiscouragedFunction
}
foreach ($pages as $name => $page) {
    foreach (['en_US', 'et_EE'] as $locale) {
        StoreLocale::use($locale);
        $strings = [];
        foreach ($page['strings'] as $phrase) {
            $strings[$phrase] = (string)__($phrase);
        }
        $data = ['html' => $render($page['template'], $block($page['viewModel']), $escaper), 'strings' => $strings];
        file_put_contents( // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $build . '/' . $name . '.' . $locale . '.js',
            'window.smailyAdminPages = window.smailyAdminPages || {};' . "\n"
            . 'window.smailyAdminPages[' . json_encode($name . '.' . $locale) . '] = '
            . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
        );
    }
}
StoreLocale::reset();
