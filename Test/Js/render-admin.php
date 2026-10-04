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
 * To add a screen: add an entry to $pages below ('template' may list several
 * templates, rendered one after another; 'data' is the block's other data).
 */

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

/**
 * The template's $block: the view model, the block's other data and an admin URL.
 */
$block = static fn (object $viewModel, array $data = []): object => new class ($viewModel, $data) {
    /**
     * @param object $viewModel
     * @param array<string, mixed> $data
     */
    public function __construct(private readonly object $viewModel, private readonly array $data)
    {
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getData(string $key)
    {
        return $key === 'view_model' ? $this->viewModel : ($this->data[$key] ?? null);
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

/*
 * The Campaign Intelligence panel (view/adminhtml/templates/panel/intelligence.phtml)
 * with its behaviour (panel/panels-js.phtml), Campaign Intelligence not
 * connected yet: the initial setup's step and the Settings tab, where the
 * import cards render too (PRO-3741). No Storefront URL is saved, except on
 * the setup step's intelligence-setup-storefront page (PRO-3745).
 */
$intelligenceViewModel = static fn (string $storefrontUrl): object => new class ($storefrontUrl) {
    /**
     * @param string $storefrontUrl
     */
    public function __construct(private readonly string $storefrontUrl)
    {
    }

    /**
     * @return string
     */
    public function getSavedStorefrontUrl(): string
    {
        return $this->storefrontUrl;
    }

    /**
     * @return bool
     */
    public function isEngineRefused(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isCookieRestrictionOnEverywhere(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function getWebsiteQuery(): array
    {
        return [];
    }

    /**
     * @return string
     */
    public function getBootJson(): string
    {
        return (string)json_encode([
            'connected' => true,
            'verified' => true,
            'planBlocked' => false,
            'setupCompleted' => false,
            'storeId' => 1,
            'connection' => ['subdomain' => 'demo', 'username' => 'api', 'hasPassword' => true, 'multilingualMode' => 'single'],
            'websiteAccount' => ['subdomain' => 'demo', 'username' => 'api'],
            'multilingual' => ['languages' => ['en'], 'fallbackLanguage' => 'en'],
            'subscribers' => [],
            'automations' => [],
            'intelligence' => ['connected' => false, 'tenantName' => '', 'engineVersion' => '', 'browseTracking' => false],
            'rss' => ['enabled' => false],
            'totals' => [],
        ]);
    }
};
$intelligence = $intelligenceViewModel('');
$intelligenceStrings = [
    'The catalog import has started',
    'Held back: the catalog import is canceled. Start it any time under Marketing > Smaily Connect > Settings > Intelligence.',
    'Canceled: %1 products were already queued for sending and still reach Campaign Intelligence; the rest are not sent. Start the catalog import again any time under Marketing > Smaily Connect > Settings > Intelligence.',
    'The catalog import had already finished, so there was nothing left to hold back.',
    'Using a separate storefront? Set its Storefront URL before you connect, so that the catalog import sends the storefront\'s product links: finish the setup without connecting, enter the address under Marketing > Smaily Connect > Settings > Connection > Using a separate storefront? > Storefront URL, then connect under Settings > Intelligence. Or connect now and press Hold back the import.',
];
$intelligenceTemplates = [
    $root . '/view/adminhtml/templates/panel/intelligence.phtml',
    $root . '/view/adminhtml/templates/panel/panels-js.phtml',
];
$pages['intelligence-setup'] = [
    'template' => $intelligenceTemplates,
    'viewModel' => $intelligence,
    'strings' => $intelligenceStrings,
];
$pages['intelligence-setup-storefront'] = [
    'template' => $intelligenceTemplates,
    'viewModel' => $intelligenceViewModel('https://shop.example.com'),
    'strings' => $intelligenceStrings,
];
$pages['intelligence-settings'] = [
    'template' => $intelligenceTemplates,
    'viewModel' => $intelligence,
    'data' => ['context' => 'settings'],
    'strings' => $intelligenceStrings,
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
        $html = '';
        foreach ((array)$page['template'] as $template) {
            $html .= $render($template, $block($page['viewModel'], $page['data'] ?? []), $escaper);
        }
        $data = ['html' => $html, 'strings' => $strings];
        file_put_contents( // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $build . '/' . $name . '.' . $locale . '.js',
            'window.smailyAdminPages = window.smailyAdminPages || {};' . "\n"
            . 'window.smailyAdminPages[' . json_encode($name . '.' . $locale) . '] = '
            . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
        );
    }
}
StoreLocale::reset();
