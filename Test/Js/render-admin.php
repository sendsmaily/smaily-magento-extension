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

// Laminas's escaper calls ord() on a multibyte character when it escapes an
// HTML attribute holding one (an Estonian phrase); PHP 8.5 reports that as
// deprecated, and the notice would be printed into the rendered page.
error_reporting(E_ALL & ~E_DEPRECATED);

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

/**
 * The template's $block: the view model, the block's other data, an admin URL,
 * the store's base URL and a child template rendered the way Magento's
 * Template block renders one (the block's 'children' data maps a child's name
 * to its template; a child not listed renders empty).
 */
$block = static fn (object $viewModel, array $data = []): object => new class ($viewModel, $data, $escaper, $root) {
    /**
     * @param object $viewModel
     * @param array<string, mixed> $data
     * @param Escaper $escaper
     * @param string $root
     */
    public function __construct(
        private readonly object $viewModel,
        private readonly array $data,
        private readonly Escaper $escaper,
        private readonly string $root
    ) {
    }

    /**
     * @param string $template a module template id, Smaily_Connect::<path>
     * @return string
     */
    public function getTemplateFile(string $template): string
    {
        return $this->root . '/view/adminhtml/templates/' . explode('::', $template, 2)[1];
    }

    /**
     * @param string $fileName
     * @return string
     */
    public function fetchView(string $fileName): string
    {
        $block = $this;
        $escaper = $this->escaper;
        ob_start();
        include $fileName;

        return (string)ob_get_clean();
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
     * @param string $name
     * @return string
     */
    public function getChildHtml(string $name): string
    {
        $template = $this->data['children'][$name] ?? null;

        return $template === null ? '' : $this->fetchView($template);
    }

    /**
     * @param string $route
     * @param array<string, mixed> $params
     * @return string
     */
    public function getUrl(string $route, array $params = []): string
    {
        return 'https://store.example/admin/' . $route . '/';
    }

    /**
     * @return string
     */
    public function getBaseUrl(): string
    {
        return 'https://store.example/';
    }
};

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
$automationsViewModel = static fn (array $rows, bool $refused): object => new class ($rows, $refused) {
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param bool $refused
     */
    public function __construct(private readonly array $rows, private readonly bool $refused)
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
     * @return bool
     */
    public function isEngineRefused(): bool
    {
        return $this->refused;
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
};
$pages = [
    'automations' => [
        'template' => $root . '/view/adminhtml/templates/config/engine-automations.phtml',
        'viewModel' => $automationsViewModel([
            $trigger('replenish_due', 'Replenishment due', true),
            $trigger('post_purchase', 'Post-purchase', false),
            $trigger('winback_risk', 'Win-back', true),
        ], false),
        'strings' => [
            'Off',
            'Test mode',
            'Active',
            'Saved.',
            'Saved. Reload the page to see the state of each trigger.',
            'Smaily switches real sends on after you confirm. Until then the trigger runs in test mode.',
        ],
    ],
    // The same tab while Campaign Intelligence refuses the account (PRO-2465).
    'automations-refused' => [
        'template' => $root . '/view/adminhtml/templates/config/engine-automations.phtml',
        'viewModel' => $automationsViewModel([], true),
        'strings' => [
            'Your Campaign Intelligence account is not active',
            'Your Campaign Intelligence account is not active, so its automations cannot be read or saved.'
                . ' Once Smaily tells you the account is active, press Check again under Settings > Intelligence.',
        ],
    ],
];

/*
 * The Campaign Intelligence panel (view/adminhtml/templates/panel/intelligence.phtml)
 * with its behaviour (panel/panels-js.phtml), Campaign Intelligence not
 * connected yet: the initial setup's step and the Settings tab, where the
 * import cards render too (PRO-3741, PRO-3790). No Storefront URL is saved, except on
 * the -storefront pages of each (PRO-3745). The connection-setup page puts the
 * initial setup's Connect step (panel/connection.phtml) in front of its
 * Intelligence step: one store language, credentials saved, no Storefront URL
 * (PRO-3802). The setup-completed pages render the initial setup itself
 * (wizard/index.phtml) with its Connect and Intelligence steps, reopened after
 * it was finished: with a Storefront URL saved and Campaign Intelligence
 * connected, and with neither (PRO-3913). Magento's cookie restriction mode
 * is on, except where a Storefront URL is saved (PRO-3918). The Settings
 * tab's import cards serve the stalled import harness too (PRO-3915,
 * PRO-3923). The same view model serves the Contacts tab (PRO-1970).
 */
$intelligenceViewModel = static fn (
    string $storefrontUrl,
    bool $intelligenceConnected = false
): object => new class ($storefrontUrl, $intelligenceConnected) {
    /**
     * @param string $storefrontUrl
     * @param bool $intelligenceConnected
     */
    public function __construct(
        private readonly string $storefrontUrl,
        private readonly bool $intelligenceConnected
    ) {
    }

    /**
     * @return bool
     */
    public function hasMultipleWebsites(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function hasSelectedWebsite(): bool
    {
        return true;
    }

    /**
     * @return array<int, string>
     */
    public function getWebsiteOptions(): array
    {
        return [];
    }

    /**
     * @return bool
     */
    public function isSetupCompleted(): bool
    {
        return true;
    }

    /**
     * @return int
     */
    public function getStartStep(): int
    {
        return 1;
    }

    /**
     * @return int
     */
    public function getReachedStep(): int
    {
        return 5;
    }

    /**
     * @return bool
     */
    public function isSmailyVerified(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getSavedSubdomain(): string
    {
        return 'demo';
    }

    /**
     * @return string
     */
    public function getSavedUsername(): string
    {
        return 'api';
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
    public function isApiOnlyStore(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isStorefrontDisclosureOpen(): bool
    {
        return $this->storefrontUrl !== '';
    }

    /**
     * @return bool
     */
    public function isMultilingual(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function getDetectedLanguages(): array
    {
        return ['en'];
    }

    /**
     * @return string
     */
    public function getMultilingualMode(): string
    {
        return 'single';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getMultilingualAccounts(): array
    {
        return [];
    }

    /**
     * @return string
     */
    public function getFallbackLanguage(): string
    {
        return 'en';
    }

    /**
     * @return bool
     */
    public function isEngineRefused(): bool
    {
        return false;
    }

    /**
     * Off where a Storefront URL is saved, so the -storefront pages show
     * which consent note a separate storefront gets (PRO-3918).
     *
     * @return bool
     */
    public function isCookieRestrictionOnEverywhere(): bool
    {
        return $this->storefrontUrl === '';
    }

    /**
     * The consent-source note is drawn, hidden, beside a Storefront URL's
     * note (PRO-3924).
     *
     * @return string
     */
    public function getConsentGuideUrl(): string
    {
        return 'https://example.com/consent-guide';
    }

    /**
     * @return array<string, string>
     */
    public function getWebsiteQuery(): array
    {
        return [];
    }

    /**
     * The Contacts tab (PRO-1970): contact sync on, Subscribers only saved.
     *
     * @return bool
     */
    public function isSyncEnabled(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getSyncMode(): string
    {
        return 'consent';
    }

    /**
     * @return string[]
     */
    public function getSelectedSyncFields(): array
    {
        return [];
    }

    /**
     * The contacts the import sends under each mode: one, so the singular
     * phrase renders, and more under All customers.
     *
     * @return array<string, int>
     */
    public function getContactImportCounts(): array
    {
        return ['consent' => 1, 'legitimate_interest' => 42, 'checkout_optin' => 0];
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
            'subscribers' => [
                'syncEnabled' => true,
                'syncMode' => 'consent',
                'checkoutOptin' => true,
                'suppressOptinEmails' => true,
                'includeGuests' => false,
            ],
            'automations' => [],
            'intelligence' => [
                'connected' => $this->intelligenceConnected,
                'tenantName' => $this->intelligenceConnected ? 'Pilot' : '',
                'engineVersion' => $this->intelligenceConnected ? '1.12.0' : '',
                'browseTracking' => false,
            ],
            'rss' => ['enabled' => false],
        ]);
    }
};
$intelligence = $intelligenceViewModel('');
$intelligenceStrings = [
    'The catalog import has started',
    'The customers import has started',
    'Your existing customer accounts go to Campaign Intelligence once, in the background, after the catalog import.'
        . ' To hold it back, press Cancel import on the Customers card under Marketing > Smaily Connect > Settings > Intelligence.',
    'Held back: the catalog import is canceled. Start it any time under Marketing > Smaily Connect > Settings > Intelligence.',
    'Canceled: %1 products were already queued for sending and still reach Campaign Intelligence; the rest are not sent. Start the catalog import again any time under Marketing > Smaily Connect > Settings > Intelligence.',
    'The catalog import had already finished, so there was nothing left to hold back.',
    'Using a separate storefront? Set its Storefront URL before you connect, so that the catalog import sends the storefront\'s product links: go back to the Connect step, open Using a separate storefront? and enter the address as Storefront URL. Or connect now and press Hold back the import.',
    'Using a separate storefront? Set its Storefront URL under Settings > Connection > Using a separate storefront? before you connect, so that the catalog import sends the storefront\'s product links. Or connect now and press Hold back the import.',
    'Consent comes from Magento’s cookie notice: browse events are sent only after the visitor allows cookies.',
    'Your separate storefront sends the browse events itself, so its own cookie consent banner decides when they are sent.',
    'Stalled',
    'Stalled — nothing has moved this import for over an hour; %1 of %2 synced so far.'
        . ' Press Run again to start a fresh import. If it stalls again, check that Magento\'s cron runs.',
    'Stalled — this import waits behind another import that nothing has moved for over an hour: Settings > %1.'
        . ' Cancel that import, or press Run again on its card, and this one follows.',
    'Stopped',
    'Stopped before an error — %1 of %2 synced so far. Press Run again to run it again.',
    'Contacts',
    'Initial contact import',
    'Intelligence',
    'Run again',
    'Running',
    'Importing…',
    '%1 of %2',
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
$pages['connection-setup'] = [
    'template' => [
        $root . '/view/adminhtml/templates/panel/connection.phtml',
        $root . '/view/adminhtml/templates/panel/intelligence.phtml',
        $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ],
    'viewModel' => $intelligence,
    'strings' => array_merge($intelligenceStrings, [
        'Using a separate storefront?',
        'Storefront URL',
        'Enter the storefront\'s address only, starting with https:// — for example https://shop.example.com — without a path or a query.',
    ]),
];
$pages['intelligence-settings-storefront'] = [
    'template' => $intelligenceTemplates,
    'viewModel' => $intelligenceViewModel('https://shop.example.com'),
    'data' => ['context' => 'settings'],
    'strings' => $intelligenceStrings,
];
/*
 * The Contacts tab (view/adminhtml/templates/panel/subscribers.phtml) with its
 * behaviour, for the contacts import card's estimate (PRO-1970).
 */
$pages['contacts-settings'] = [
    'template' => [
        $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ],
    'viewModel' => $intelligence,
    'data' => ['context' => 'settings'],
    'strings' => [
        'This import sends %1 contact from this website. It runs in the background,'
            . ' a chunk per minute, and never slows the storefront down.',
        'This import sends %1 contacts from this website. It runs in the background,'
            . ' a chunk per minute, and never slows the storefront down.',
    ],
];
$setupCompleted = [
    'template' => $root . '/view/adminhtml/templates/wizard/index.phtml',
    'data' => ['children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.intelligence' => $root . '/view/adminhtml/templates/panel/intelligence.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => [
        'Storefront URL',
        'Saved. Run the catalog import again under Intelligence > Historical imports,'
            . ' so that Campaign Intelligence gets the new product links.',
    ],
];
$pages['setup-completed-storefront'] = $setupCompleted
    + ['viewModel' => $intelligenceViewModel('https://shop.example.com', true)];
$pages['setup-completed'] = $setupCompleted + ['viewModel' => $intelligence];

/*
 * The Dashboard (view/adminhtml/templates/dashboard/index.phtml): set up,
 * Smaily and Campaign Intelligence connected, browse tracking on, nothing
 * failed. The -storefront page has a Storefront URL saved (PRO-3918); the
 * -unsent page has had no nightly product list for three nights, the last
 * one held back by product changes waiting in the Log (PRO-3914).
 */
$dashboardViewModel = static fn (
    bool $separateStorefront,
    bool $unsent = false
): object => new class ($separateStorefront, $unsent) {
    /**
     * @param bool $separateStorefront
     * @param bool $unsent
     */
    public function __construct(private readonly bool $separateStorefront, private readonly bool $unsent)
    {
    }

    /**
     * @return string
     */
    public function getVerdict(): string
    {
        return $this->unsent
            ? \Smaily\Connect\ViewModel\Adminhtml\DashboardData::VERDICT_DEGRADED
            : \Smaily\Connect\ViewModel\Adminhtml\DashboardData::VERDICT_OK;
    }

    /**
     * @return array{nights: int, reason: string}|null
     */
    public function getUnsentProductList(): ?array
    {
        return $this->unsent ? [
            'nights' => 3,
            'reason' => (string)__(
                'Last night product changes were still waiting to be sent in the Log, and the list waits for them.'
            ),
        ] : null;
    }

    /**
     * @return int
     */
    public function getFailedLast24h(): int
    {
        return 0;
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
    public function isEngineDown(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isSmailyPlanBlocked(): bool
    {
        return false;
    }

    /**
     * @return array{sub: string, variant: string, label: string}
     */
    public function getEngineStateLabels(): array
    {
        return ['sub' => 'Connected (Pilot)', 'variant' => 'active', 'label' => 'On'];
    }

    /**
     * @return bool
     */
    public function isSmailyConnected(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getSmailySubdomain(): string
    {
        return 'demo';
    }

    /**
     * @return bool
     */
    public function isEngineConnected(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isBrowseTrackingEnabled(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function hasSeparateStorefront(): bool
    {
        return $this->separateStorefront;
    }

    /**
     * @return int
     */
    public function getContactSyncsDelivered(): int
    {
        return 12;
    }

    /**
     * @return int
     */
    public function getCatalogItemsDelivered(): int
    {
        return 340;
    }

    /**
     * @return int
     */
    public function getQueuedToday(): int
    {
        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentActivity(): array
    {
        return [];
    }
};
$dashboardStrings = [
    'Browse tracking',
    'Script live on storefront',
    'Your separate storefront must send the events itself',
    'All systems normal',
    'Needs attention',
    'No nightly product list has gone to Campaign Intelligence for %1 nights',
    'Smaily Connect is running, but no nightly product list has gone to Campaign Intelligence for %1 nights.',
    'Last night product changes were still waiting to be sent in the Log, and the list waits for them.',
];
$pages['dashboard'] = [
    'template' => $root . '/view/adminhtml/templates/dashboard/index.phtml',
    'viewModel' => $dashboardViewModel(false),
    'strings' => $dashboardStrings,
];
$pages['dashboard-storefront'] = [
    'template' => $root . '/view/adminhtml/templates/dashboard/index.phtml',
    'viewModel' => $dashboardViewModel(true),
    'strings' => $dashboardStrings,
];
$pages['dashboard-unsent'] = [
    'template' => $root . '/view/adminhtml/templates/dashboard/index.phtml',
    'viewModel' => $dashboardViewModel(false, true),
    'strings' => $dashboardStrings,
];

$render = static function (string $template, object $block, Escaper $escaper): string {
    ob_start();
    include $template;

    return (string)ob_get_clean();
};

// A page that could not be written would leave the harnesses reading an
// older build, so any write failure stops the run (non-zero exit).
$build = __DIR__ . '/build';
if (!is_dir($build) && !mkdir($build) && !is_dir($build)) { // phpcs:ignore Magento2.Functions.DiscouragedFunction
    throw new RuntimeException('Cannot create ' . $build . '.');
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
        $file = $build . '/' . $name . '.' . $locale . '.js';
        $written = file_put_contents( // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $file,
            'window.smailyAdminPages = window.smailyAdminPages || {};' . "\n"
            . 'window.smailyAdminPages[' . json_encode($name . '.' . $locale) . '] = '
            . json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
        );
        if ($written === false) {
            throw new RuntimeException('Cannot write ' . $file . '.');
        }
    }
}
StoreLocale::reset();

// The merchant user guide (docs/site/index.html) as a string, for
// Test/Js/user-guide-site.html: a page opened from file:// cannot read
// another file's document, but it can load this script and render the
// guide into a same-origin frame.
$guide = file_get_contents($root . '/docs/site/index.html'); // phpcs:ignore Magento2.Functions.DiscouragedFunction
$file = $build . '/user-guide-site.js';
if ($guide === false || file_put_contents( // phpcs:ignore Magento2.Functions.DiscouragedFunction
    $file,
    'window.smailyUserGuideSite = '
    . json_encode($guide, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
) === false) {
    throw new RuntimeException('Cannot write ' . $file . '.');
}
