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
 * PRO-3923). The same view model serves the Contacts tab (PRO-1970) and the
 * initial setup's Contacts step (PRO-4010).
 */
$intelligenceViewModel = static fn (
    string $storefrontUrl,
    bool $intelligenceConnected = false,
    bool $syncEnabled = true,
    bool $contactSyncActive = false
): object => new class ($storefrontUrl, $intelligenceConnected, $syncEnabled, $contactSyncActive) {
    /**
     * @param string $storefrontUrl
     * @param bool $intelligenceConnected
     * @param bool $syncEnabled
     * @param bool $contactSyncActive
     */
    public function __construct(
        private readonly string $storefrontUrl,
        private readonly bool $intelligenceConnected,
        private readonly bool $syncEnabled,
        private readonly bool $contactSyncActive
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
     * One website: the store's values are its own, with no exceptions.
     *
     * @return bool
     */
    public function isStoreWide(): bool
    {
        return true;
    }

    /**
     * @param string $section
     * @return bool
     */
    public function hasOwnValues(string $section): bool
    {
        return false;
    }

    /**
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function getWebsiteOverrides(string $section): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getWebsiteNames(): array
    {
        return ['Main Website'];
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
     * The Contacts tab (PRO-1970): contact sync on, Subscribers only saved;
     * off on the contacts-setup-off (PRO-4010) and contacts-settings-off
     * (PRO-4016) pages.
     *
     * @return bool
     */
    public function isSyncEnabled(): bool
    {
        return $this->syncEnabled;
    }

    /**
     * A new website: its Contacts settings were never saved, so its
     * contacts do not sync yet (PRO-4036) — except on the contacts-settings
     * page, whose contacts sync (PRO-4037).
     *
     * @return bool
     */
    public function isContactSyncActive(): bool
    {
        return $this->contactSyncActive;
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
                'syncEnabled' => $this->syncEnabled,
                'syncActive' => $this->contactSyncActive,
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
 * behaviour, for the contacts import card's estimate (PRO-1970) and its
 * outcome for a website whose contact sync was not active (PRO-4037).
 */
$pages['contacts-settings'] = [
    'template' => [
        $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ],
    'viewModel' => $intelligenceViewModel('', false, true, true),
    'data' => ['context' => 'settings'],
    'strings' => [
        'This import sends %1 contact from this website. It runs in the background,'
            . ' a chunk per minute, and never slows the storefront down.',
        'This import sends %1 contacts from this website. It runs in the background,'
            . ' a chunk per minute, and never slows the storefront down.',
        'Done, %1 of %2 synced.',
        'Contact sync was off for %1, so nothing was sent from there.'
            . ' Turn it on and save the Contacts settings, then press Run again.',
    ],
];
/*
 * The initial setup's Contacts step (the same panel without the Settings
 * context), for a website whose contact sync is on and for one whose sync is
 * off, as a 2.8.x Enable Module = No leaves it (PRO-4010).
 */
$contactsSetupTemplates = [
    $root . '/view/adminhtml/templates/panel/subscribers.phtml',
    $root . '/view/adminhtml/templates/panel/panels-js.phtml',
];
$contactsUnsaved = 'Contacts start syncing to Smaily once the Contacts settings are saved,'
    . ' so an import would send nothing yet. Save them to import.';
$pages['contacts-setup'] = [
    'template' => $contactsSetupTemplates,
    'viewModel' => $intelligence,
    'strings' => [
        'Sync contacts to Smaily',
        'Contacts start syncing to Smaily when you save this step.',
        $contactsUnsaved,
    ],
];
$pages['contacts-setup-off'] = [
    'template' => $contactsSetupTemplates,
    'viewModel' => $intelligenceViewModel('', false, false),
    'strings' => ['Sync contacts to Smaily'],
];
/*
 * The Contacts tab for a website whose contact sync is off: Start import
 * waits for the switch to be saved on (PRO-4016).
 */
$pages['contacts-settings-off'] = [
    'template' => $contactsSetupTemplates,
    'viewModel' => $intelligenceViewModel('', false, false),
    'data' => ['context' => 'settings'],
    'strings' => ['Sync contacts to Smaily'],
];
/*
 * The Contacts tab of a new website: sync on, its Contacts settings never
 * saved, so Start import waits for their save (PRO-4037).
 */
$pages['contacts-settings-new'] = [
    'template' => $contactsSetupTemplates,
    'viewModel' => $intelligence,
    'data' => ['context' => 'settings'],
    'strings' => [$contactsUnsaved],
];
/*
 * The transactional-email settings of Settings (PRO-4062): the account
 * block of the Connection tab (panel/transactional-account.phtml) and the
 * triggers of the Automations tab (panel/transactional-triggers.phtml) with
 * their behaviour (panel/panels-js.phtml). The -off page: one website, one
 * store language, "Send transactional emails" off and no transactional
 * account saved. The -connected page: two websites, two store languages,
 * the switch on, the website's own account connected and its Order
 * confirmation workflows saved, the account saved for all websites another
 * one; Customer details on.
 */
$transactionalViewModel = static fn (bool $connected): object => new class ($connected) {
    /**
     * @param bool $connected
     */
    public function __construct(private readonly bool $connected)
    {
    }

    /**
     * @return bool
     */
    public function hasMultipleWebsites(): bool
    {
        return $this->connected;
    }

    /**
     * @return string[]
     */
    public function getDetectedLanguages(): array
    {
        return $this->connected ? ['et', 'en'] : ['en'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionalAccount(): array
    {
        $shared = $this->connected
            ? ['subdomain' => 'shop-tx', 'username' => 'api', 'hasPassword' => true]
            : ['subdomain' => '', 'username' => '', 'hasPassword' => false];

        return $this->connected
            ? [
                'enabled' => true,
                'subdomain' => 'ee-tx',
                'username' => 'api-ee',
                'hasPassword' => true,
                'websiteAccount' => true,
                'verified' => true,
                'shared' => $shared,
            ]
            : ['enabled' => false, 'websiteAccount' => false, 'verified' => false, 'shared' => $shared] + $shared;
    }

    /**
     * @return bool
     */
    public function isTransactionalPersonalDataEnabled(): bool
    {
        return $this->connected;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getTransactionalTriggers(): array
    {
        return [
            'order_confirmation' => [
                'enabled' => false,
                'workflows' => $this->connected
                    ? [
                        'et' => ['workflowId' => 31, 'isDefaultFallback' => true],
                        'en' => ['workflowId' => 32, 'isDefaultFallback' => false],
                    ]
                    : [],
            ],
            'shipment_confirmation' => ['enabled' => false, 'workflows' => []],
        ];
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
            'setupCompleted' => true,
            'storeId' => 1,
            'connection' => ['subdomain' => 'demo', 'username' => 'api', 'hasPassword' => true, 'multilingualMode' => 'single'],
            // No Connection panel is rendered: an empty account lets
            // collect.connect() post what the transactional card adds.
            'websiteAccount' => ['subdomain' => '', 'username' => ''],
            'multilingual' => ['languages' => $this->getDetectedLanguages(), 'fallbackLanguage' => ''],
            'subscribers' => ['syncEnabled' => true, 'syncActive' => true, 'syncMode' => 'consent'],
            'automations' => [],
            'intelligence' => ['connected' => false, 'tenantName' => '', 'engineVersion' => '', 'browseTracking' => false],
            'rss' => ['enabled' => false],
        ]);
    }
};
$transactionalTemplates = [
    $root . '/view/adminhtml/templates/panel/transactional-account.phtml',
    $root . '/view/adminhtml/templates/panel/transactional-triggers.phtml',
    $root . '/view/adminhtml/templates/panel/panels-js.phtml',
];
$transactionalStrings = [
    'Transactional emails',
    'Send transactional emails',
    'Transactional Smaily account',
    'Order confirmation',
    'Shipping confirmation',
    'Customer details',
    'Include addresses, phone and order note',
    'Use a separate account for this website',
    'Connected',
    'Not connected',
];
$pages['transactional-off'] = [
    'template' => $transactionalTemplates,
    'viewModel' => $transactionalViewModel(false),
    'strings' => $transactionalStrings,
];
$pages['transactional-connected'] = [
    'template' => $transactionalTemplates,
    'viewModel' => $transactionalViewModel(true),
    'strings' => $transactionalStrings,
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
 * The store-wide setup and its website exceptions (CON-179) on a store with
 * three websites (Estonia, Latvia, Lithuania) and two store languages, in
 * One account, per-language workflows mode, Smaily login details saved but
 * the setup not finished. The setup-websites page renders the initial setup
 * (wizard/index.phtml) with its Connect, Contacts and Automations steps:
 * Latvia holds its own Smaily account and its own Contacts settings. The
 * settings-website page renders Settings (settings/index.phtml) with its
 * Connection and Contacts tabs for Latvia, which holds its own Contacts
 * settings but uses the Smaily account for all websites.
 */
$websitesViewModel = static fn (bool $storeWide): object => new class ($storeWide) {
    /**
     * @param bool $storeWide
     */
    public function __construct(private readonly bool $storeWide)
    {
    }

    /**
     * @return bool
     */
    public function isStoreWide(): bool
    {
        return $this->storeWide;
    }

    /**
     * @return bool
     */
    public function hasMultipleWebsites(): bool
    {
        return true;
    }

    /**
     * @return array<int, string>
     */
    public function getWebsiteOptions(): array
    {
        return [1 => 'Estonia', 2 => 'Latvia', 3 => 'Lithuania'];
    }

    /**
     * @return string[]
     */
    public function getWebsiteNames(): array
    {
        return ['Estonia', 'Latvia', 'Lithuania'];
    }

    /**
     * @return int
     */
    public function getSelectedWebsiteId(): int
    {
        return $this->storeWide ? 0 : 2;
    }

    /**
     * @param string $section
     * @return bool
     */
    public function hasOwnValues(string $section): bool
    {
        return !$this->storeWide && $section === 'subscribers';
    }

    /**
     * @param string $section
     * @return bool
     */
    public function isOwnRequired(string $section): bool
    {
        return false;
    }

    /**
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function getWebsiteOverrides(string $section): array
    {
        if (!$this->storeWide) {
            return [];
        }

        return match ($section) {
            'connection' => [['websiteId' => 2, 'name' => 'Latvia', 'values' => [
                [(string)__('Subdomain'), 'lv-shop'],
                [(string)__('API username'), 'lv-api'],
            ]]],
            'subscribers' => [['websiteId' => 2, 'name' => 'Latvia', 'values' => [
                [(string)__('Sync contacts to Smaily'), (string)__('Off')],
            ]]],
            default => [],
        };
    }

    /**
     * @return array{_query: array{website: int}}
     */
    public function getWebsiteQuery(): array
    {
        return ['_query' => ['website' => $this->storeWide ? 0 : 2]];
    }

    /**
     * @return bool
     */
    public function isSetupCompleted(): bool
    {
        return false;
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
        return 1;
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
        return 'shop';
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
        return '';
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
        return false;
    }

    /**
     * @return bool
     */
    public function isMultilingual(): bool
    {
        return true;
    }

    /**
     * @return string[]
     */
    public function getDetectedLanguages(): array
    {
        return ['et', 'lv'];
    }

    /**
     * @return string
     */
    public function getMultilingualMode(): string
    {
        return 'b';
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
        return '';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getSavedMappings(): array
    {
        return [];
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
     * @return string
     */
    public function getConsentGuideUrl(): string
    {
        return 'https://example.com/consent-guide';
    }

    /**
     * @return string
     */
    public function getSmailyAccountUrl(): string
    {
        return 'https://shop.sendsmaily.net';
    }

    /**
     * @return bool
     */
    public function isSyncEnabled(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isContactSyncActive(): bool
    {
        return false;
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
     * @return array<string, int>
     */
    public function getContactImportCounts(): array
    {
        return ['consent' => 12, 'legitimate_interest' => 40, 'checkout_optin' => 0];
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
            'storeId' => $this->storeWide ? 0 : 2,
            'connection' => ['subdomain' => 'shop', 'username' => 'api', 'hasPassword' => true, 'multilingualMode' => 'b'],
            'websiteAccount' => ['subdomain' => 'shop', 'username' => 'api'],
            'multilingual' => ['languages' => ['et', 'lv'], 'fallbackLanguage' => ''],
            'subscribers' => [
                'syncEnabled' => true,
                'syncActive' => false,
                'syncMode' => 'consent',
                'syncFields' => [],
                'checkoutOptin' => true,
                'suppressOptinEmails' => true,
                'includeGuests' => false,
            ],
            'automations' => [
                'welcomeEnabled' => false,
                'welcomeWorkflow' => '',
                'firstOrderEnabled' => false,
                'firstOrderWorkflow' => '',
                'abandonedEnabled' => false,
                'abandonedWorkflow' => '',
                'abandonedCutoff' => 30,
            ],
            'intelligence' => ['connected' => false, 'tenantName' => '', 'engineVersion' => '', 'browseTracking' => false],
            'rss' => ['enabled' => false],
        ]);
    }
};
$websitesStrings = [
    'Websites with their own values',
    'Keep its own values',
    'Use this step\'s values',
    'Saving this step starts contact sync on every website: %1.',
    'A website that keeps its own Contacts settings below syncs as those settings say.',
    'Per-language Smaily accounts are set for one website: under Settings > Connection, pick the website and tick'
        . ' Use its own Smaily account on this website.',
    'Use its own Smaily account on this website',
    'Use its own Contacts settings on this website',
    'Save to remove this website\'s own values: it then uses the settings saved for all websites.',
    'This import sends %1 contacts from all websites. It runs in the background, a chunk per minute,'
        . ' and never slows the storefront down.',
];
$pages['setup-websites'] = [
    'template' => $root . '/view/adminhtml/templates/wizard/index.phtml',
    'viewModel' => $websitesViewModel(true),
    'data' => ['children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.subscribers' => $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.intelligence' => $root . '/view/adminhtml/templates/panel/intelligence.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => $websitesStrings,
];
$pages['settings-website'] = [
    'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
    'viewModel' => $websitesViewModel(false),
    'data' => ['context' => 'settings', 'children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.subscribers' => $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => $websitesStrings,
];

/*
 * The settings-modes page (CON-184) renders Settings with its Connection and
 * Automations tabs for a one-website store with ET and LV store views, saved
 * in per-language Smaily accounts (mode A), which holds rows of both
 * per-language modes: mode A's Welcome workflow for ET (the fallback), and
 * mode B's Welcome workflows for ET and LV (LV the fallback).
 */
$pages['settings-modes'] = [
    'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
    'viewModel' => new class ($websitesViewModel(false)) {
        /**
         * @param object $inner
         */
        public function __construct(private readonly object $inner)
        {
        }

        /**
         * @param string $name
         * @param array<int, mixed> $arguments
         * @return mixed
         */
        public function __call(string $name, array $arguments): mixed
        {
            return $this->inner->$name(...$arguments);
        }

        /**
         * @return bool
         */
        public function hasMultipleWebsites(): bool
        {
            return false;
        }

        /**
         * @return string
         */
        public function getMultilingualMode(): string
        {
            return 'a';
        }

        /**
         * @param string $mode
         * @return array<string, array{workflowId: int, isDefaultFallback: bool}>
         */
        public function getSavedMappings(string $mode): array
        {
            return $mode === 'a'
                ? ['welcome|et' => ['workflowId' => 101, 'isDefaultFallback' => true]]
                : [
                    'welcome|et' => ['workflowId' => 201, 'isDefaultFallback' => false],
                    'welcome|lv' => ['workflowId' => 202, 'isDefaultFallback' => true],
                ];
        }

        /**
         * @return string
         */
        public function getBootJson(): string
        {
            $boot = json_decode($this->inner->getBootJson(), true, 512, JSON_THROW_ON_ERROR);
            $boot['connection']['multilingualMode'] = 'a';

            return (string)json_encode($boot);
        }
    },
    'data' => ['context' => 'settings', 'children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => ['Some languages have no workflow'],
];

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
