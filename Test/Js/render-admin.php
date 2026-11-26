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
 * templates, rendered one after another, each a path or [path, view model,
 * data] when it has a view model of its own; 'data' is the block's other data).
 */

use Magento\Framework\Escaper;
use Magento\Framework\Translate\InlineInterface;
use Magento\Framework\ZendEscaper;
use Smaily\Connect\Model\Engine\EmailTestMode;
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
 * to its template, or to [path, view model, data] for a child block with a
 * view model of its own; a child not listed renders empty).
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
        if (is_array($template)) {
            // A child block of its own: [path, view model, data] (CON-363).
            [$file, $viewModel, $data] = $template;

            return (new self($viewModel, $data, $this->escaper, $this->root))->fetchView($file);
        }

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

    /**
     * The module's static file in the repository, as a harness page under
     * Test/Js reaches it (CON-372).
     *
     * @param string $fileId Smaily_Connect::<path under view/adminhtml/web>
     * @return string
     */
    public function getViewFileUrl(string $fileId): string
    {
        return '../../view/adminhtml/web/' . explode('::', $fileId, 2)[1];
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
    'setup' => null,
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
     * @return string
     */
    public function getAdminEmail(): string
    {
        return 'admin@store.example';
    }

    /**
     * A row's §12 `setup[]` item (`setup_item`) is described here, while
     * the page renders, so its texts are in the page's locale (CON-458).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        return array_map(
            static fn (array $row): array => isset($row['setup_item'])
                ? ['setup' => EmailTestMode::describe($row['setup_item'])] + $row
                : $row,
            $this->rows
        );
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
        ],
    ],
    /*
     * Test mode and the test send of the Campaign Intelligence emails
     * (CON-458), each email enabled, as §12 `setup[]` reads it:
     * Replenishment due in test mode, Post-purchase sending to real
     * customers at the ramp's second step, Win-back stopped until Smaily
     * reviews the stop, and Browse abandon without a setup item (an engine
     * before contract v1.15.0), so without the switch.
     */
    'automations-test-mode' => [
        'template' => $root . '/view/adminhtml/templates/config/engine-automations.phtml',
        'viewModel' => $automationsViewModel([
            ['enabled' => true, 'setup_item' => ['setup_state' => 'test', 'daily_limit' => null]]
                + $trigger('replenish_due', 'Replenishment due', true),
            ['enabled' => true, 'setup_item' => ['setup_state' => 'live', 'daily_limit' => 25]]
                + $trigger('post_purchase', 'Post-purchase', false),
            ['enabled' => true, 'setup_item' => [
                'setup_state' => 'stopped',
                'stop_reason' => 'auto_stop_complaint',
                'go_live_blockers' => ['stopped_needs_review'],
            ]] + $trigger('winback_risk', 'Win-back', true),
            ['enabled' => true] + $trigger('browse_abandon', 'Browse abandon', true),
        ], false),
        'strings' => [
            'Test mode',
            'Active',
            'The email goes to test addresses only.',
            'Send test email',
            'This address is added to the Smaily list as a test contact.',
            'Sending to real customers · up to %1 emails today',
            'Sending to real customers',
            'Smaily reviews this stop before the email can send again',
            'Starts when your sender domain is verified in Smaily',
            'Turn off test mode?',
            '“%1” will start going to your store\'s real customers, at first up to 10 emails a day, then 25 and 50.',
            'Turn off',
            'Cancel',
            'Smaily accepted the test email. Check the inbox.',
            'The test email cannot be sent yet:',
            'No Smaily workflow sends this email yet',
            'No customer has recommendations for this email yet',
            'Smaily refused to send from %1. Check your sender domain in Smaily.',
            'Too many test emails were sent in the last hour. Try again later.',
            'Smaily did not answer, so nothing was sent. Press Send test email to try again.',
            'Test mode stays on:',
            'The email has no subject',
            'Campaign Intelligence did not answer. Try again in a minute.',
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
     * @return array<int, array<string, mixed>>
     */
    public function getOwnAccountWebsites(): array
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
    public function isWebsiteSetUp(): bool
    {
        return true;
    }

    /**
     * @return bool
     */
    public function isShapeChoiceFirst(): bool
    {
        return false;
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
     * No other website on the Smaily account uses another contact sync mode
     * (CON-432).
     *
     * @return array<int, array{website: string, mode: string}>
     */
    public function getSharedAccountModes(): array
    {
        return [];
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
    'The customer accounts your contact sync puts into Smaily, and every customer with an order in the order history'
        . ' Campaign Intelligence takes, go to Campaign Intelligence once, in the background, after the catalog import.'
        . ' To hold it back, press Cancel import on the Customers card under Marketing > Smaily Connect > Settings > Intelligence.',
    'The orders import has started',
    'Your order history goes to Campaign Intelligence once, in the background, after the customers import, newest first:'
        . ' the recent orders first, so that Campaign Intelligence can start, then the older ones. No order older than the'
        . ' history Campaign Intelligence takes is sent. To hold it back, press Cancel import on the Orders card under'
        . ' Marketing > Smaily Connect > Settings > Intelligence.',
    'Sending the orders of the last %1 months first…',
    'The last %1 months are in: Campaign Intelligence can start. Older orders, back to %2 months, follow in the background…',
    'Held back: the catalog import is canceled, and the customers and orders imports waiting behind it do not start. Start each any time under Marketing > Smaily Connect > Settings > Intelligence.',
    'Canceled: %1 products were already queued for sending and still reach Campaign Intelligence; the rest are not sent, and the customers and orders imports waiting behind it do not start. Start each import again any time under Marketing > Smaily Connect > Settings > Intelligence.',
    'The catalog import had already finished, so there was nothing left to hold back of it; the customers and orders imports waiting behind it do not start.',
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
    'Details for your developer: %1',
    'Done',
    'Done, %1 of %2 synced — %3 skipped because Magento could not read them;'
        . ' var/log/smaily_connect.log on the server lists them by id.',
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
/*
 * Settings > Intelligence with Campaign Intelligence connected, for
 * "Open Campaign Intelligence" (CON-230, Test/Js/console-entry.html).
 */
$pages['intelligence-settings-connected'] = [
    'template' => $intelligenceTemplates,
    'viewModel' => $intelligenceViewModel('', true),
    'data' => ['context' => 'settings'],
    'strings' => [
        'Open Campaign Intelligence',
        'Your browser blocked the new tab. Allow pop-ups for this page and try again.',
        'Campaign Intelligence could not be opened. Try again in a minute.',
    ],
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
 * behaviour, for the contacts import card's estimate (PRO-1970), its
 * outcome for a website whose contact sync was not active (PRO-4037) and
 * the contacts Smaily did not take (CON-432).
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
        'Done, %1 of %2 synced — Smaily did not take %3. The Log keeps the reason for each contact.',
        'View them in the Log',
    ],
];
/*
 * The Contacts tab and the initial setup's Contacts step of a website whose
 * Smaily account another website uses with another contact sync mode: both
 * warn, naming each website with its mode (CON-432).
 */
$sharedAccountViewModel = static fn (object $inner): object => new class ($inner) {
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
     * @return array<int, array{website: string, mode: string}>
     */
    public function getSharedAccountModes(): array
    {
        return [
            ['website' => 'Main Website', 'mode' => 'consent'],
            ['website' => 'Second Website', 'mode' => 'legitimate_interest'],
        ];
    }
};
$sharedAccountStrings = [
    'Websites on this Smaily account use different contact sync modes',
    'Contact sync modes on this Smaily account: %1. Smaily keeps one subscription status per email address,'
        . ' so a contact that one website sends can be marked subscribed in another website too.'
        . ' Give these websites the same contact sync mode, or each website its own Smaily account.',
    'Subscribers only (consent)',
    'All customers (legitimate interest)',
];
$pages['contacts-settings-shared'] = [
    'template' => [
        $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ],
    'viewModel' => $sharedAccountViewModel($intelligenceViewModel('', false, true, true)),
    'data' => ['context' => 'settings'],
    'strings' => $sharedAccountStrings,
];
$pages['contacts-setup-shared'] = [
    'template' => [
        $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ],
    'viewModel' => $sharedAccountViewModel($intelligenceViewModel('', false, true, true)),
    'strings' => $sharedAccountStrings,
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
        'Also sync guest buyers who did not tick the newsletter box',
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
    'strings' => [$contactsUnsaved, 'Also sync guest buyers who did not tick the newsletter box'],
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
     * @return bool
     */
    public function isSeparateStores(): bool
    {
        return false;
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
    'data' => ['context' => 'settings'],
    'strings' => $transactionalStrings,
];
$pages['transactional-connected'] = [
    'template' => $transactionalTemplates,
    'viewModel' => $transactionalViewModel(true),
    'data' => ['context' => 'settings'],
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
// The Overview shows Campaign Intelligence connected there (CON-461).
$pages['setup-completed-storefront'] = [
    'viewModel' => $intelligenceViewModel('https://shop.example.com', true),
    'strings' => array_merge($setupCompleted['strings'], ['Campaign Intelligence', 'Connected']),
] + $setupCompleted;
$pages['setup-completed'] = $setupCompleted + ['viewModel' => $intelligence];

/*
 * The initial setup's Connect step before the setup is finished (CON-417),
 * wizard/index.phtml with panel/connection.phtml: a single store with a
 * Smaily account saved that Smaily did not accept at the last check
 * (setup-connect), and one it accepted (setup-connect-verified). The view
 * model is the setup-completed page's, with the setup not finished.
 */
$setupConnectViewModel = static fn (bool $verified): object => new class ($intelligenceViewModel(''), $verified) {
    /**
     * @param object $inner
     * @param bool $verified
     */
    public function __construct(private readonly object $inner, private readonly bool $verified)
    {
    }

    /**
     * @param string $name
     * @param array<int, mixed> $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments)
    {
        return $this->inner->$name(...$arguments);
    }

    /**
     * @return bool
     */
    public function isWebsiteSetUp(): bool
    {
        return false;
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
        return $this->verified;
    }

    /**
     * @return string
     */
    public function getBootJson(): string
    {
        $boot = json_decode($this->inner->getBootJson(), true, 512, JSON_THROW_ON_ERROR);
        $boot['verified'] = $this->verified;

        return (string)json_encode($boot);
    }
};
foreach (['setup-connect' => false, 'setup-connect-verified' => true] as $page => $verified) {
    $pages[$page] = [
        'template' => $setupCompleted['template'],
        'viewModel' => $setupConnectViewModel($verified),
        'data' => $setupCompleted['data'],
        'strings' => ['Test your Smaily connection to continue.', 'Saving failed.'],
    ];
}

/*
 * The store-wide setup and its website exceptions (CON-179) on a store with
 * three websites (Estonia, Latvia, Lithuania) and two store languages, in
 * One account, per-language automations mode, Smaily login details saved but
 * the setup not finished. The setup-websites page renders the initial setup
 * (wizard/index.phtml) with its Connect, Contacts and Automations steps:
 * Latvia holds its own Smaily account — another account than the
 * store-wide one, so it keeps its own automations (CON-190) — and its own
 * Contacts settings; below the Smaily account, the optional
 * transactional-email account, not saved yet, and in the Automations step
 * its triggers (CON-33). The settings-website page renders Settings (settings/index.phtml) with its
 * Connection and Contacts tabs for Latvia, which holds its own Contacts
 * settings but uses the Smaily account for all websites. The
 * settings-own-account page renders Settings with its Connection and
 * Automations tabs for Latvia on another Smaily account than the store-wide
 * one, with no workflow of its own (CON-190).
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
     * @return bool
     */
    public function needsOwnWorkflows(): bool
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
            // Latvia's other Smaily account keeps its own automations (CON-190).
            'automations' => [['websiteId' => 2, 'name' => 'Latvia', 'required' => true, 'values' => [
                [(string)__('Welcome') . ' · ' . (string)__('Smaily Workflow'), '#7'],
            ]]],
            default => [],
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOwnAccountWebsites(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionalAccount(): array
    {
        $none = ['subdomain' => '', 'username' => '', 'hasPassword' => false];

        return ['enabled' => false, 'websiteAccount' => false, 'verified' => false, 'shared' => $none] + $none;
    }

    /**
     * @return bool
     */
    public function isTransactionalPersonalDataEnabled(): bool
    {
        return false;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getTransactionalTriggers(): array
    {
        return [
            'order_confirmation' => ['enabled' => false, 'workflows' => []],
            'shipment_confirmation' => ['enabled' => false, 'workflows' => []],
        ];
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
    public function isWebsiteSetUp(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isShapeChoiceFirst(): bool
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isSeparateStores(): bool
    {
        return false;
    }

    /**
     * @return array<int, array{name: string, subdomain: string}>
     */
    public function getWebsiteAccounts(): array
    {
        return [1 => ['name' => 'Estonia', 'subdomain' => 'shop'], 2 => ['name' => 'Latvia', 'subdomain' => 'lv-shop'],
            3 => ['name' => 'Lithuania', 'subdomain' => '']];
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
     * Each card's workflow per language (CON-461): none picked, the first
     * language the Default fallback.
     *
     * @param string $mode
     * @return array<string, array{workflows: array<string, int>, fallback: string}>
     */
    public function getLanguageWorkflows(string $mode): array
    {
        $card = ['workflows' => ['et' => 0, 'lv' => 0], 'fallback' => 'et'];

        return ['welcome' => $card, 'first_order' => $card, 'abandoned_cart' => $card];
    }

    /**
     * @return array<int, string>
     */
    public function getDiscountRules(): array
    {
        return [12 => 'Welcome 10%'];
    }

    /**
     * @return bool
     */
    public function hasCartPriceRules(): bool
    {
        return true;
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
     * No other website on the Smaily account uses another contact sync mode
     * (CON-432).
     *
     * @return array<int, array{website: string, mode: string}>
     */
    public function getSharedAccountModes(): array
    {
        return [];
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
    'Separate Smaily accounts are set for one website: under Settings > Connection, pick the website and tick'
        . ' Use its own Smaily account on this website.',
    'Use its own Smaily account on this website',
    'Use its own Contacts settings on this website',
    'Save to remove this website\'s own values: it then uses the settings saved for all websites.',
    'This import sends %1 contacts from all websites. It runs in the background, a chunk per minute,'
        . ' and never slows the storefront down.',
    'A website with another Smaily account than the one saved for all websites keeps its own automations:'
        . ' their workflows come from its own account.',
];
// The setup's Overview (CON-461): its step, what is active and the cron card.
$overviewStrings = [
    'Overview',
    'Continue',
    'Finish',
    'Back',
    'Smaily Connect is configured and ready. Make sure that all settings are correct and click Finish to save.',
    'Syncing starts once Smaily accepts the credentials — check them in Settings > Connection and test the connection.',
    'What\'s active',
    'Smaily connected',
    'Contact sync enabled',
    'Welcome',
    'First order',
    'Abandoned cart',
    'Campaign Intelligence',
    'Connected',
    'Not connected (optional)',
    'Background tasks',
    'Make sure the Magento cron runs every minute — deliveries are queued and flushed by the smaily_connect cron group.',
    'All customers (legitimate interest)',
    'One account, per-language automations',
    'Also sync guest buyers who did not tick the newsletter box',
];
$pages['setup-websites'] = [
    'template' => $root . '/view/adminhtml/templates/wizard/index.phtml',
    'viewModel' => $websitesViewModel(true),
    'data' => ['children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.transactional.account' => $root . '/view/adminhtml/templates/panel/transactional-account.phtml',
        'panel.subscribers' => $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.transactional.triggers' => $root . '/view/adminhtml/templates/panel/transactional-triggers.phtml',
        'panel.intelligence' => $root . '/view/adminhtml/templates/panel/intelligence.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => array_merge($websitesStrings, $transactionalStrings, $overviewStrings, [
        'Once connected, set up the Order confirmation and Shipping confirmation triggers in the Automations step.',
        'Send transactional emails via transactional Smaily account, which you can connect in the first setup step.'
            . ' Transactional emails should be sent from a separate account than marketing emails.',
    ]),
];

/*
 * The same store's initial setup reopened after it was finished (CON-185).
 * The setup-websites-reopened page: no Smaily account is saved for all
 * websites, Estonia and Latvia hold their own (a 2.8.x shape). The
 * setup-websites-completed page: the Smaily account for all websites is
 * saved, Latvia holds its own.
 */
$reopenedViewModel = static fn (bool $storeWideAccount): object => new class (
    $websitesViewModel(true),
    $storeWideAccount
) {
    /**
     * @param object $inner
     * @param bool $storeWideAccount
     */
    public function __construct(private readonly object $inner, private readonly bool $storeWideAccount)
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
    public function isWebsiteSetUp(): bool
    {
        return true;
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
    public function getReachedStep(): int
    {
        return 5;
    }

    /**
     * @return string
     */
    public function getSavedSubdomain(): string
    {
        return $this->storeWideAccount ? 'shop' : '';
    }

    /**
     * @return string
     */
    public function getSavedUsername(): string
    {
        return $this->storeWideAccount ? 'api' : '';
    }

    /**
     * @param string $section
     * @return array<int, array<string, mixed>>
     */
    public function getWebsiteOverrides(string $section): array
    {
        if ($this->storeWideAccount || $section !== 'connection') {
            return $this->inner->getWebsiteOverrides($section);
        }

        return $this->getOwnAccountWebsites();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getOwnAccountWebsites(): array
    {
        if ($this->storeWideAccount) {
            return [];
        }

        return [
            ['websiteId' => 1, 'name' => 'Estonia', 'required' => false, 'values' => [
                [(string)__('Subdomain'), 'ee-shop'],
                [(string)__('API username'), 'ee-api'],
                [(string)__('API password'), '••••••••'],
            ]],
            ['websiteId' => 2, 'name' => 'Latvia', 'required' => false, 'values' => [
                [(string)__('Subdomain'), 'lv-shop'],
                [(string)__('API username'), 'lv-api'],
                [(string)__('API password'), '••••••••'],
            ]],
        ];
    }
};
$reopenedStrings = array_merge($websitesStrings, [
    'No Smaily account is saved for all websites, so each of these websites uses its own. Press Continue to keep'
        . ' them, or Edit credentials to save a Smaily account for all websites.',
]);
foreach (['setup-websites-reopened' => false, 'setup-websites-completed' => true] as $page => $storeWideAccount) {
    $pages[$page] = ['viewModel' => $reopenedViewModel($storeWideAccount), 'strings' => $reopenedStrings]
        + $pages['setup-websites'];
}
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

$pages['settings-own-account'] = [
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
         * @param string $section
         * @return bool
         */
        public function hasOwnValues(string $section): bool
        {
            return $section !== 'subscribers';
        }

        /**
         * @param string $section
         * @return bool
         */
        public function isOwnRequired(string $section): bool
        {
            return $section === 'automations';
        }

        /**
         * @return bool
         */
        public function needsOwnWorkflows(): bool
        {
            return true;
        }
    },
    'data' => ['context' => 'settings', 'children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => [
        'Use its own automations on this website',
        'A website with another Smaily account than the one saved for all websites keeps its own automations:'
            . ' their workflows come from its own account.',
        'Pick this website\'s workflows',
        'This website uses another Smaily account than the one saved for all websites, so it starts only workflows'
            . ' picked from its own account.'
            . ' No automation starts on it until you pick its workflows below and save.',
    ],
];

/*
 * How the websites are run (CON-279) on the same three-website store. The
 * setup-layout-first page renders the initial setup of a store whose layout
 * is not known yet: it opens on the choice. The setup-separate and
 * settings-separate pages render the initial setup and Settings of a store
 * set up as separate stores, for Latvia: each website is a store of its own
 * (Lithuania has no Smaily account yet), with the Connect step and the
 * Connection tab, the transactional-email account and Campaign Intelligence.
 */
$layoutViewModel = static fn (bool $storeWide, bool $first, bool $separate): object => new class (
    $websitesViewModel($storeWide),
    $first,
    $separate
) {
    /**
     * @param object $inner
     * @param bool $first
     * @param bool $separate
     */
    public function __construct(
        private readonly object $inner,
        private readonly bool $first,
        private readonly bool $separate
    ) {
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
    public function isShapeChoiceFirst(): bool
    {
        return $this->first;
    }

    /**
     * @return bool
     */
    public function isSeparateStores(): bool
    {
        return $this->separate;
    }

    /**
     * @param string $section
     * @return bool
     */
    public function hasOwnValues(string $section): bool
    {
        return $this->separate || $this->inner->hasOwnValues($section);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTransactionalAccount(): array
    {
        return ['websiteAccount' => $this->separate] + $this->inner->getTransactionalAccount();
    }
};
$layoutStrings = [
    'Your websites',
    'One store in several versions',
    'Separate stores',
    'Change',
    'Not set up',
    'How are your websites run?',
    'This installation has %1 websites: %2.',
    'Each website keeps the settings it uses now, as its own. Change them for each website afterwards.',
    'All websites then use the settings of %1: its Smaily account, Contacts settings, automations and'
        . ' transactional emails. The other websites\' own settings are removed.',
    'Campaign Intelligence is one connection for the whole installation, whichever website you pick: it covers every'
        . ' website (%1).',
    'Continue',
    'Save',
    'Websites with their own values',
    'Use its own Smaily account on this website',
];
$pages['setup-layout-first'] = [
    'viewModel' => $layoutViewModel(true, true, false),
    'strings' => $layoutStrings,
] + $pages['setup-websites'];
$pages['setup-separate'] = [
    'viewModel' => $layoutViewModel(false, false, true),
    'strings' => $layoutStrings,
] + $pages['setup-websites'];
$pages['settings-separate'] = [
    'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
    'viewModel' => $layoutViewModel(false, false, true),
    'data' => ['context' => 'settings', 'children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.transactional.account' => $root . '/view/adminhtml/templates/panel/transactional-account.phtml',
        'panel.subscribers' => $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        'panel.intelligence' => $root . '/view/adminhtml/templates/panel/intelligence.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => $layoutStrings,
];

/*
 * The settings-modes page (CON-184) renders Settings with its Connection and
 * Automations tabs for a one-website store with ET and LV store views, saved
 * in per-language Smaily accounts (mode A), which holds rows of both
 * per-language modes: mode A's Welcome workflow for ET (the fallback), and
 * mode B's Welcome workflows for ET and LV (LV the fallback). The
 * settings-modes-single page: the same website saved in the stored default,
 * 'single', which has no card (CON-461). The settings-modes-unsaved page:
 * the same website with no mode saved, shown in mode B (CON-461).
 */
$modesViewModel = static fn (string $mode, bool $saved): object => new class (
    $websitesViewModel(false),
    $mode,
    $saved
) {
    /**
     * @param object $inner
     * @param string $mode
     * @param bool $saved
     */
    public function __construct(
        private readonly object $inner,
        private readonly string $mode,
        private readonly bool $saved
    ) {
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
        return $this->mode;
    }

    /**
     * @param string $mode
     * @return array<string, array{workflows: array<string, int>, fallback: string}>
     */
    public function getLanguageWorkflows(string $mode): array
    {
        return ['welcome' => $mode === 'a'
            ? ['workflows' => ['et' => 101, 'lv' => 0], 'fallback' => 'et']
            : ['workflows' => ['et' => 201, 'lv' => 202], 'fallback' => 'lv'],
        ] + $this->inner->getLanguageWorkflows($mode);
    }

    /**
     * @return string
     */
    public function getBootJson(): string
    {
        $boot = json_decode($this->inner->getBootJson(), true, 512, JSON_THROW_ON_ERROR);
        $boot['connection']['multilingualMode'] = $this->mode;
        $boot['connection']['multilingualModeSaved'] = $this->saved;

        return (string)json_encode($boot);
    }
};
foreach ([
    'settings-modes' => ['a', true],
    'settings-modes-single' => ['single', true],
    'settings-modes-unsaved' => ['b', false],
] as $page => [$mode, $saved]) {
    $pages[$page] = [
        'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
        'viewModel' => $modesViewModel($mode, $saved),
        'data' => ['context' => 'settings', 'children' => [
            'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
            'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
            'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
        ]],
        'strings' => ['Some languages have no workflow', 'Single language'],
    ];
}

/*
 * The per-language card pages (CON-461) render the initial setup
 * (setup-per-language, wizard/index.phtml) and Settings
 * (settings-per-language) of the three-website store above, in One account,
 * per-language workflows (mode B) with ET and LV: Welcome sends ET 201 and
 * LV 202, LV's row the Default fallback; the other automations have no
 * workflow, the first language their Default fallback.
 */
$perLanguageViewModel = static fn (bool $storeWide): object => new class ($websitesViewModel($storeWide)) {
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
     * @param string $mode
     * @return array<string, array{workflows: array<string, int>, fallback: string}>
     */
    public function getLanguageWorkflows(string $mode): array
    {
        return ['welcome' => ['workflows' => ['et' => 201, 'lv' => 202], 'fallback' => 'lv']]
            + $this->inner->getLanguageWorkflows($mode);
    }
};
$perLanguageStrings = [
    'Default fallback',
    'Smaily Workflow',
    'Per-language workflows',
    'Contacts whose language has no workflow get the workflow of the row marked Default fallback.',
];
$pages['setup-per-language'] = [
    'template' => $root . '/view/adminhtml/templates/wizard/index.phtml',
    'viewModel' => $perLanguageViewModel(true),
    'data' => ['children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.subscribers' => $root . '/view/adminhtml/templates/panel/subscribers.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => $perLanguageStrings,
];
$pages['settings-per-language'] = [
    'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
    'viewModel' => $perLanguageViewModel(false),
    'data' => ['context' => 'settings', 'children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => $perLanguageStrings,
];

/*
 * The settings-account-change page (CON-396) renders Settings for the
 * Latvia website of the two-website store above, saved in one account with
 * per-language workflows (mode B) on the Smaily account `shop`.
 */
$accountChangeStrings = [
    'Switching the multilingual mode changes how contacts and automations are routed. The default fallback account'
        . ' takes the place of the Smaily account saved now; where it is another Smaily account, the workflows picked'
        . ' from the saved account are removed. Workflows picked earlier for per-language accounts are removed too.'
        . ' Continue?',
    'This replaces a saved Smaily account. The workflows picked from the replaced account are removed: those'
        . ' automations send nothing until you pick workflows of the new account under Automations. Continue?',
    'Switching the multilingual mode changes how contacts and automations are routed. Per-language accounts and'
        . ' workflow mappings that no longer apply stop being used (per-store-view credentials are removed on save).'
        . ' Continue?',
];
$pages['settings-account-change'] = [
    'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
    'viewModel' => $websitesViewModel(false),
    'data' => ['context' => 'settings', 'children' => [
        'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
        'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
        'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
    ]],
    'strings' => $accountChangeStrings,
];

/*
 * The Automations tab's discount code (CON-198) for the Latvia website of
 * the two-website store above: the settings-discount page lists two cart
 * price rules that can generate codes; the settings-discount-empty page has
 * no cart price rule at all; on the settings-discount-no-autogen page the
 * store has cart price rules, but none can generate codes.
 */
$discountViewModel = static fn (array $rules, bool $hasRules): object => new class (
    $websitesViewModel(false),
    $rules,
    $hasRules
) {
    /**
     * @param object $inner
     * @param array<int, string> $rules
     * @param bool $hasRules
     */
    public function __construct(
        private readonly object $inner,
        private readonly array $rules,
        private readonly bool $hasRules
    ) {
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
     * @return array<int, string>
     */
    public function getDiscountRules(): array
    {
        return $this->rules;
    }

    /**
     * @return bool
     */
    public function hasCartPriceRules(): bool
    {
        return $this->hasRules;
    }
};
$discountStrings = [
    'Send a discount code',
    'Make the discount in Magento first',
    'Your cart price rules are not listed: a rule is listed only when it is active for these websites and has'
        . ' Coupon "Specific Coupon" with "Use Auto Generation" ticked.',
    'Send a discount code is on, but no cart price rule is picked. Pick a rule, or switch the discount code off.',
];
$discountPages = [
    'settings-discount' => [[12 => 'Welcome 10%', 14 => 'Come back 5 EUR'], true],
    'settings-discount-empty' => [[], false],
    'settings-discount-no-autogen' => [[], true],
];
foreach ($discountPages as $page => [$rules, $hasRules]) {
    $pages[$page] = [
        'template' => $root . '/view/adminhtml/templates/settings/index.phtml',
        'viewModel' => $discountViewModel($rules, $hasRules),
        'data' => ['context' => 'settings', 'children' => [
            'panel.connection' => $root . '/view/adminhtml/templates/panel/connection.phtml',
            'panel.automations' => $root . '/view/adminhtml/templates/panel/automations.phtml',
            'panel.js' => $root . '/view/adminhtml/templates/panel/panels-js.phtml',
        ]],
        'strings' => $discountStrings,
    ];
}

/*
 * settings-long-wait: the settings-discount page of a website whose
 * abandoned-cart reminder is on with a cutoff saved before the cutoff had a
 * maximum, 1440 minutes, with which no reminder is sent (CON-368).
 */
$pages['settings-long-wait'] = [
    'viewModel' => new class ($discountViewModel([], true)) {
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
         * @return string
         */
        public function getBootJson(): string
        {
            $boot = json_decode($this->inner->getBootJson(), true);
            $boot['automations']['abandonedEnabled'] = true;
            $boot['automations']['abandonedCutoff'] = 1440;

            return (string)json_encode($boot);
        }
    },
    'template' => $pages['settings-discount']['template'],
    'data' => $pages['settings-discount']['data'],
    'strings' => $discountStrings,
];

/*
 * The Dashboard (view/adminhtml/templates/dashboard/index.phtml): set up,
 * Smaily and Campaign Intelligence connected, browse tracking on, nothing
 * failed. The -storefront page has a Storefront URL saved (PRO-3918); the
 * -unsent page has had no nightly product list for three nights, the last
 * one held back by product changes waiting in the Log (PRO-3914); the
 * -separate page is the three-website store set up as separate stores,
 * with Latvia selected (CON-281).
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

    /**
     * @return array<string, mixed>
     */
    public function getWebsiteQuery(): array
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
    'Settings',
    'Log',
    'Initial setup',
    'User Guide',
    'the full merchant documentation: every feature, setting and troubleshooting steps',
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
$pages['dashboard-separate'] = [
    'template' => $root . '/view/adminhtml/templates/dashboard/index.phtml',
    'viewModel' => $dashboardViewModel(false),
    'data' => ['children' => [
        'website.picker' => [
            $root . '/view/adminhtml/templates/panel/website-picker.phtml',
            $layoutViewModel(false, false, true),
            [],
        ],
    ]],
    'strings' => array_merge($dashboardStrings, ['Not set up']),
];

/*
 * "Edit design" on the email cards (CON-257): the three store-run cards
 * (panel/automations.phtml), the two transactional cards with one workflow
 * per language (panel/transactional-triggers.phtml), two Campaign
 * Intelligence cards — Replenishment due on, Win-back off
 * (config/engine-automations.phtml) — and panel/email-designs.phtml, with
 * Campaign Intelligence connected on https://engine.invalid (a host that
 * never answers). Each template has its own view model, as on the page. On
 * email-designs the admin is on https, so the design page opens in the
 * modal; on email-designs-tab it is not, so it opens in a tab.
 */
$emailDesignsViewModel = static fn (bool $frameable): object => new class ($frameable) {
    /**
     * @param bool $frameable
     */
    public function __construct(private readonly bool $frameable)
    {
    }

    /**
     * @return bool
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getEngineOrigin(): string
    {
        return 'https://engine.invalid';
    }

    /**
     * @return bool
     */
    public function isFrameable(): bool
    {
        return $this->frameable;
    }
};
foreach (['email-designs' => true, 'email-designs-tab' => false] as $page => $frameable) {
    $pages[$page] = [
        'template' => [
            [
                $root . '/view/adminhtml/templates/panel/automations.phtml',
                $discountViewModel([12 => 'Welcome 10%'], true),
                ['context' => 'settings'],
            ],
            [
                $root . '/view/adminhtml/templates/panel/transactional-triggers.phtml',
                $transactionalViewModel(true),
                ['context' => 'settings'],
            ],
            [
                $root . '/view/adminhtml/templates/config/engine-automations.phtml',
                $automationsViewModel([
                    ['enabled' => true] + $trigger('replenish_due', 'Replenishment due', true),
                    $trigger('winback_risk', 'Win-back', true),
                ], false),
                [],
            ],
            [
                $root . '/view/adminhtml/templates/panel/panels-js.phtml',
                $transactionalViewModel(true),
                ['context' => 'settings'],
            ],
            [$root . '/view/adminhtml/templates/panel/email-designs.phtml', $emailDesignsViewModel($frameable), []],
        ],
        'viewModel' => null,
        'strings' => [
            'Edit design',
            'Edit design: %1',
            'Email design',
            'Starter design',
            'Design edited',
            'Subject: %1',
            'Open in a new tab',
            'Opening the design page…',
            'The design page did not open.',
            'Close without saving?',
            'Your browser blocked the new tab. Allow pop-ups for this page and try again.',
            'Campaign Intelligence offers no design for this email.',
        ],
    ];
}

/*
 * The Campaign Intelligence banner and "Try Intelligence free" (CON-363):
 * panel/intelligence-banner.phtml on top of the Settings tabs (dismissible)
 * and as the Intelligence tab's child above the setup link field,
 * panel/panels-js.phtml for the connection, and
 * panel/intelligence-start.phtml once for the page. ci-start-frame: an https
 * admin, so the start window opens in the modal; ci-start-window: the admin
 * cannot frame it, so a new window. ci-banner-upsell: Smaily answered code
 * 227; ci-banner-separate: separate stores, so the start is not offered;
 * ci-banner-none: Campaign Intelligence connected; ci-banner-dashboard: the
 * dismissible banner alone, as the Dashboard draws it, without the panels;
 * ci-banner-dashboard-dismissed: the same, dismissed in the last 30 days.
 */
$bannerViewModel = static fn (
    string $variant,
    bool $offersStart = true,
    bool $frameable = true,
    bool $dismissed = false
): object => new class (
    $variant,
    $offersStart,
    $frameable,
    $dismissed
) {
    /**
     * @param string $variant
     * @param bool $offersStart
     * @param bool $frameable
     * @param bool $dismissed
     */
    public function __construct(
        private readonly string $variant,
        private readonly bool $offersStart,
        private readonly bool $frameable,
        private readonly bool $dismissed
    ) {
    }

    /**
     * @return string
     */
    public function getVariant(): string
    {
        return $this->variant;
    }

    /**
     * @return bool
     */
    public function isDismissed(): bool
    {
        return $this->dismissed;
    }

    /**
     * @return bool
     */
    public function offersStart(): bool
    {
        return $this->offersStart;
    }

    /**
     * @return string
     */
    public function getLoginUrl(): string
    {
        return 'https://shop.sendsmaily.net/';
    }

    /**
     * @return string
     */
    public function getConsultationUrl(): string
    {
        return 'https://smaily.com/consultation/';
    }

    /**
     * @return bool
     */
    public function isFrameable(): bool
    {
        return $this->frameable;
    }
};
$bannerStrings = [
    'Connect your store with Smaily, and add personalized product recommendations to your campaigns, tailored to each'
        . ' customer’s interests.',
    'Contact Smaily',
    'Try Intelligence free',
    'Open in a new window',
    'Log in to Smaily',
    'Requires a paid Smaily plan',
    'Campaign Intelligence requires a paid Smaily plan. Log in to Smaily to review your plan options.',
    'Intelligence needs a paid Smaily package. Your Smaily account is on the free package. Trying Intelligence is free,'
        . ' but it works only with a paid Smaily package. No store was created and nothing was sent.',
    'Connecting your shop · Sending your shop address · Checking your Smaily account',
    'Finish the setup in the window that opened. If the extension does not connect by itself, copy the setup link that'
        . ' window shows into Setup URL or token from Smaily under Marketing > Smaily Connect > Settings > Intelligence.',
    'The setup window did not open here.',
    'Your browser blocked the new tab. Allow pop-ups for this page and try again.',
    'Smaily refused the API login stored in this extension. Check it in the extension settings and try again.'
        . ' No store was created.',
    'Open the Smaily connection settings',
    'This Smaily account already has a store in Intelligence.',
    'Open console',
    'To connect this extension, create a setup link in the console (Settings → Connections) and paste it into Setup URL'
        . ' or token from Smaily.',
    'Smaily did not answer. Try again shortly.',
    'Campaign Intelligence could not be started. Try again later, or contact Smaily.',
    'Connecting the extension…',
    'Opening the setup window…',
    'Dismiss',
    'Engine in action',
    'Campaign Intelligence',
    'Show recommendations in your store',
    'For product pages, the cart or the order success page, add a widget instance under Content > Elements > Widgets'
        . ' with Smaily recommendations as the Type. For a page or a block, press Insert Widget... in the content editor'
        . ' and pick Smaily recommendations (in Page Builder, in a Text element or an HTML Code element). Shoppers who'
        . ' accept marketing cookies see the products Smaily recommends to them.',
    'How to add it →',
    'Connected: %1 (engine %2)',
    'The catalog import has started',
];
$bannerTemplate = $root . '/view/adminhtml/templates/panel/intelligence-banner.phtml';
$startTemplate = $root . '/view/adminhtml/templates/panel/intelligence-start.phtml';
foreach (['ci-start-frame' => true, 'ci-start-window' => false] as $page => $frameable) {
    $banner = $bannerViewModel('invite', true, $frameable);
    $pages[$page] = [
        'template' => [
            [$bannerTemplate, $banner, ['dismissible' => true]],
            [$root . '/view/adminhtml/templates/panel/intelligence.phtml', $intelligence, [
                'context' => 'settings',
                'children' => ['intelligence.banner' => [$bannerTemplate, $banner, []]],
            ]],
            [$root . '/view/adminhtml/templates/panel/panels-js.phtml', $intelligence, ['context' => 'settings']],
            [$startTemplate, $banner, []],
        ],
        'viewModel' => null,
        'strings' => $bannerStrings,
    ];
}
foreach ([
    'ci-banner-upsell' => $bannerViewModel('upsell', false),
    'ci-banner-separate' => $bannerViewModel('invite', false),
    'ci-banner-none' => $bannerViewModel(''),
] as $page => $banner) {
    $pages[$page] = [
        'template' => [
            [$root . '/view/adminhtml/templates/panel/intelligence.phtml', $intelligence, [
                'context' => 'settings',
                'children' => ['intelligence.banner' => [$bannerTemplate, $banner, []]],
            ]],
            [$startTemplate, $banner, []],
        ],
        'viewModel' => null,
        'strings' => $bannerStrings,
    ];
}
foreach (['ci-banner-dashboard' => false, 'ci-banner-dashboard-dismissed' => true] as $page => $dismissed) {
    $banner = $bannerViewModel('invite', true, true, $dismissed);
    $pages[$page] = [
        'template' => [
            [$bannerTemplate, $banner, ['dismissible' => true]],
            [$startTemplate, $banner, ['dismissible' => true]],
        ],
        'viewModel' => null,
        'strings' => $bannerStrings,
    ];
}

/*
 * The invitation to connect a Smaily account (CON-364):
 * panel/smaily-invitation-banner.phtml on top of setup step 1 (no Dismiss)
 * and on the Dashboard (dismissible). invite-setup: the setup not finished,
 * the form shown; invite-setup-completed: a finished setup's summary, the
 * form behind Edit credentials, Campaign Intelligence connected (the panels
 * hide its banners at boot, not this one); invite-setup-connected: a Smaily
 * account connected; invite-dashboard(-dismissed, -connected): the
 * Dashboard's banner, dismissed in the last 90 days, a Smaily account
 * connected.
 */
$invitationViewModel = static fn (bool $due = true, bool $dismissed = false): object => new class ($due, $dismissed) {
    /**
     * @param bool $due
     * @param bool $dismissed
     */
    public function __construct(private readonly bool $due, private readonly bool $dismissed)
    {
    }

    /**
     * @return bool
     */
    public function isDue(): bool
    {
        return $this->due;
    }

    /**
     * @return bool
     */
    public function isDismissed(): bool
    {
        return $this->dismissed;
    }

    /**
     * @return string
     */
    public function getSignupUrl(): string
    {
        return 'https://www.sendsmaily.net/account/register/';
    }

    /**
     * @return string
     */
    public function getConnectUrl(): string
    {
        return 'https://store.example/admin/smaily_connect/settings/?tab=connection';
    }

    /**
     * @return string
     */
    public function getDismissUrl(): string
    {
        return 'https://store.example/admin/smaily_connect/invitation/dismiss/?form_key=form-key';
    }
};
$invitationStrings = [
    'Connect your store to Smaily to sync contacts and set up automated emails.',
    'Connect your Smaily account',
    'Create a Smaily account',
    'Log in to Smaily: gear icon → Integrations → Create a new user (API username and password).',
    'Smaily in action',
    'Welcome email',
    'Abandoned cart reminder',
    'Automated',
    'Dismiss',
];
$invitationTemplate = $root . '/view/adminhtml/templates/panel/smaily-invitation-banner.phtml';
$pages['invite-setup'] = [
    'template' => $pages['setup-websites']['template'],
    'viewModel' => $pages['setup-websites']['viewModel'],
    'data' => ['children' => $pages['setup-websites']['data']['children']
        + ['invitation.banner' => [$invitationTemplate, $invitationViewModel(), []]]],
    'strings' => $invitationStrings,
];
foreach (['invite-setup-completed' => true, 'invite-setup-connected' => false] as $page => $due) {
    $pages[$page] = [
        'template' => $setupCompleted['template'],
        'viewModel' => $intelligenceViewModel('', true),
        'data' => ['children' => $setupCompleted['data']['children']
            + ['invitation.banner' => [$invitationTemplate, $invitationViewModel($due), []]]],
        'strings' => $invitationStrings,
    ];
}
foreach ([
    'invite-dashboard' => $invitationViewModel(),
    'invite-dashboard-dismissed' => $invitationViewModel(true, true),
    'invite-dashboard-connected' => $invitationViewModel(false),
] as $page => $invitation) {
    $pages[$page] = [
        'template' => [[$invitationTemplate, $invitation, ['dismissible' => true]]],
        'viewModel' => null,
        'strings' => $invitationStrings,
    ];
}
// invite-settings(-connected): on top of the Settings > Connection tab (no
// Dismiss), and none once a Smaily account is connected.
foreach (['invite-settings' => true, 'invite-settings-connected' => false] as $page => $due) {
    $pages[$page] = [
        'data' => ['context' => 'settings', 'children' => $pages['settings-website']['data']['children']
            + ['invitation.banner' => [$invitationTemplate, $invitationViewModel($due), []]]],
        'strings' => $invitationStrings,
    ] + $pages['settings-website'];
}

/*
 * The Smaily Connect logo above the page title (CON-372, brand.phtml): with
 * the entrance (the Dashboard) and still (every other page).
 */
foreach (['brand-entrance' => ['entrance' => true], 'brand-still' => []] as $page => $data) {
    $pages[$page] = [
        'template' => $root . '/view/adminhtml/templates/brand.phtml',
        // The template reads no view model.
        'viewModel' => new stdClass(),
        'data' => $data,
        'strings' => [],
    ];
}

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
        // A template is a path, rendered with the page's view model and
        // data, or [path, view model, data] for its own (CON-257).
        foreach ((array)$page['template'] as $template) {
            [$file, $viewModel, $data] = is_array($template)
                ? $template
                : [$template, $page['viewModel'], $page['data'] ?? []];
            $html .= $render($file, $block($viewModel, $data), $escaper);
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

// The admin stylesheet as a string, for Test/Js/brand-logos.html (CON-372)
// and Test/Js/brand-palette.html (CON-376): a page opened from file://
// cannot read the rules of a stylesheet it links, and those harnesses read
// its rules.
$css = file_get_contents($root . '/view/adminhtml/web/css/smaily-admin.css'); // phpcs:ignore Magento2.Functions.DiscouragedFunction
$file = $build . '/smaily-admin-css.js';
if ($css === false || file_put_contents( // phpcs:ignore Magento2.Functions.DiscouragedFunction
    $file,
    'window.smailyAdminCss = ' . json_encode($css, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ";\n"
) === false) {
    throw new RuntimeException('Cannot write ' . $file . '.');
}

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
