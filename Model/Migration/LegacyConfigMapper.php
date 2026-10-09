<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Migration;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\SubdomainNormalizer;

/**
 * Pure mapping of legacy 2.8.x configuration (section "smaily") onto the v3
 * paths — separated from the data patch so the transform matrix is unit
 * testable without a database.
 *
 * Notes on deliberate drops (surfaced as notices):
 * - sync frequency: v3 syncs as changes happen + a 15-min consent reconcile.
 * - captcha settings: v3 relies on Magento's native reCAPTCHA module.
 * - lastSyncedAt: the v3 reconcile cursor is sequence-based.
 *
 * "Enable Module = No" (general/enable 0) has no v3 switch: it becomes
 * contact sync, welcome and abandoned cart off at the same scope.
 */
class LegacyConfigMapper
{
    public const FLAG_ENCRYPT = 'encrypt';

    /**
     * Legacy 2.8.x sync-field name => the v3 field id (which is also the
     * Smaily wire key). Only `gender` moved: v3 sends it under the
     * cross-platform canon `user_gender` (see Config\Source\SyncFields).
     *
     * @var array<string, string>
     */
    private const SYNC_FIELD_MAP = [
        'subscription_type' => 'subscription_type',
        'customer_group' => 'customer_group',
        'customer_id' => 'customer_id',
        'prefix' => 'prefix',
        'first_name' => 'first_name',
        'last_name' => 'last_name',
        'gender' => 'user_gender',
        'birthday' => 'birthday',
    ];

    /**
     * The v3 settings that 2.8.x "Enable Module = No" stopped => their
     * etc/config.xml default (a unit test keeps the two equal).
     *
     * @var array<string, string>
     */
    public const MODULE_SWITCH_DEFAULTS = [
        Config::XML_PATH_SYNC_ENABLED => '1',
        Config::XML_PATH_WELCOME_ENABLED => '0',
        Config::XML_PATH_ABANDONED_ENABLED => '0',
    ];

    public function __construct(
        private readonly SubdomainNormalizer $subdomainNormalizer
    ) {
    }

    /**
     * Map one scope's legacy values onto v3 config rows.
     *
     * @param array<string, string|null> $legacy legacy path => value
     *     (paths relative, e.g. "general/subdomain")
     * @param array<string, string|null> $inherited the values the scope fell
     *     back to in 2.8.x (a website's: the default scope's), same keys
     * @return array{
     *     configs: array<int, array{path: string, value: string, flags: string[]}>,
     *     notices: string[]
     * }
     */
    public function map(array $legacy, array $inherited = []): array
    {
        $configs = [];
        $notices = [];
        $set = function (string $path, string $value, array $flags = []) use (&$configs): void {
            $configs[] = ['path' => $path, 'value' => $value, 'flags' => $flags];
        };

        // Connection.
        $subdomain = $this->subdomainNormalizer->normalize((string)($legacy['general/subdomain'] ?? ''));
        if ($subdomain !== '') {
            $set(Config::XML_PATH_SUBDOMAIN, $subdomain);
        }
        if (!empty($legacy['general/username'])) {
            $set(Config::XML_PATH_USERNAME, trim((string)$legacy['general/username']));
        }
        if (!empty($legacy['general/password'])) {
            // Legacy stored the API password in plain text; encrypt on the way in.
            $set(Config::XML_PATH_PASSWORD, (string)$legacy['general/password'], [self::FLAG_ENCRYPT]);
        }

        // Newsletter opt-in autoresponder -> welcome automation.
        $welcomeWorkflow = (int)($legacy['subscribe/workflowId'] ?? 0);
        if ($welcomeWorkflow > 0) {
            $set(Config::XML_PATH_WELCOME_WORKFLOW, (string)$welcomeWorkflow);
        }
        $welcomeSent = $this->sent(
            $legacy,
            $inherited,
            'subscribe/enableNewsletterSubscriptions',
            'subscribe/workflowId'
        );
        if ($welcomeSent !== null) {
            $set(Config::XML_PATH_WELCOME_ENABLED, $welcomeSent);
        }

        // Subscriber synchronization.
        if (isset($legacy['sync/enableCronSync'])) {
            $set(Config::XML_PATH_SYNC_ENABLED, $this->flag($legacy, 'sync/enableCronSync') ? '1' : '0');
        }
        if (!empty($legacy['sync/fields'])) {
            $legacyFields = array_map('trim', explode(',', (string)$legacy['sync/fields']));
            $fields = array_values(array_intersect_key(
                self::SYNC_FIELD_MAP,
                array_flip($legacyFields)
            ));
            if ($fields) {
                $set(Config::XML_PATH_SYNC_FIELDS, implode(',', $fields));
            }
        }
        if (isset($legacy['sync/frequency'])) {
            $notices[] = (string)__(
                'Subscriber sync frequency is no longer configurable: v3 syncs in near-real-time '
                . '(observers + durable queue) with a 15-minute Smaily→Magento consent reconcile.'
            );
        }

        // Abandoned cart.
        $abandonedSent = $this->sent($legacy, $inherited, 'abandoned/enableAbandonedCart', 'abandoned/autoresponderId');
        if ($abandonedSent !== null) {
            $set(Config::XML_PATH_ABANDONED_ENABLED, $abandonedSent);
        }
        $abandonedWorkflow = (int)($legacy['abandoned/autoresponderId'] ?? 0);
        if ($abandonedWorkflow > 0) {
            $set(Config::XML_PATH_ABANDONED_WORKFLOW, (string)$abandonedWorkflow);
        }
        if (!empty($legacy['abandoned/syncTime'])) {
            $set(
                Config::XML_PATH_ABANDONED_CUTOFF,
                (string)$this->intervalToMinutes((string)$legacy['abandoned/syncTime'])
            );
        }

        // Captcha settings are replaced by Magento's native reCAPTCHA.
        if ($this->flag($legacy, 'subscribe/enableCaptcha')) {
            $notices[] = (string)__(
                'The legacy newsletter captcha settings were not migrated: '
                . 'enable Magento\'s built-in reCAPTCHA for newsletter forms instead '
                . '(Stores > Configuration > Security > Google reCAPTCHA Storefront).'
            );
        }

        // Enable Module = No stopped this scope's sync, opt-in and abandoned cart.
        if ($this->moduleSwitch($legacy) === false) {
            $configs = array_values(array_filter(
                $configs,
                static fn (array $config): bool => !isset(self::MODULE_SWITCH_DEFAULTS[$config['path']])
            ));
            foreach (array_keys(self::MODULE_SWITCH_DEFAULTS) as $path) {
                $set($path, '0');
            }
        }

        return ['configs' => $configs, 'notices' => $notices];
    }

    /**
     * The scope's own 2.8.x "Enable Module" value: true for Yes, false for
     * No, null when the scope has none.
     *
     * @param array<string, string|null> $legacy
     */
    public function moduleSwitch(array $legacy): ?bool
    {
        return isset($legacy['general/enable']) ? $this->flag($legacy, 'general/enable') : null;
    }

    /**
     * What contact sync, welcome and abandoned cart resolve to from one
     * scope's legacy values with Enable Module left aside: the carried-over
     * value, else the etc/config.xml default.
     *
     * @param array<string, string|null> $legacy
     * @return array<string, string> v3 path => value
     */
    public function moduleSwitchValues(array $legacy): array
    {
        unset($legacy['general/enable']);
        $values = [];
        foreach ($this->map($legacy)['configs'] as $config) {
            if (isset(self::MODULE_SWITCH_DEFAULTS[$config['path']])) {
                $values[$config['path']] = $config['value'];
            }
        }

        return $values + self::MODULE_SWITCH_DEFAULTS;
    }

    /**
     * Whether 2.8.x had the newsletter opt-in on at a website with no
     * Autoresponder ID, so it sent no welcome email there and the welcome
     * automation stays off: each value the website's own, else the default
     * scope's. Enable Module = No there leaves nothing to tell.
     *
     * @param array<string, string|null> $legacy the website's legacy values
     * @param array<string, string|null> $inherited the default scope's
     */
    public function welcomeWithoutWorkflow(array $legacy, array $inherited): bool
    {
        return $this->onWithoutWorkflow(
            $legacy,
            $inherited,
            'subscribe/enableNewsletterSubscriptions',
            'subscribe/workflowId'
        );
    }

    /**
     * Whether 2.8.x had Enable Abandoned Cart on at a website with no
     * Autoresponder ID, so it sent no reminder there and the abandoned-cart
     * automation stays off: each value the website's own, else the default
     * scope's. Enable Module = No there leaves nothing to tell.
     *
     * @param array<string, string|null> $legacy the website's legacy values
     * @param array<string, string|null> $inherited the default scope's
     */
    public function abandonedCartWithoutWorkflow(array $legacy, array $inherited): bool
    {
        return $this->onWithoutWorkflow(
            $legacy,
            $inherited,
            'abandoned/enableAbandonedCart',
            'abandoned/autoresponderId'
        );
    }

    /**
     * Convert the legacy "N:minutes" / "N:hour" interval to minutes,
     * clamped to the v3 safe range (10 min .. 24 h).
     */
    public function intervalToMinutes(string $interval): int
    {
        [$amount, $unit] = array_pad(explode(':', $interval, 2), 2, 'minutes');
        $minutes = (int)$amount * (str_starts_with(trim($unit), 'hour') ? 60 : 1);

        return max(Config::MIN_ABANDONED_CUTOFF_MINUTES, min(1440, $minutes));
    }

    /**
     * Whether 2.8.x sent the automation's email at this scope: its switch on
     * and its Autoresponder ID set, each the scope's own value else the
     * inherited one ('1' or '0'); null when the scope has neither value of
     * its own, so it keeps following the scope above it.
     *
     * @param array<string, string|null> $legacy
     * @param array<string, string|null> $inherited
     */
    private function sent(array $legacy, array $inherited, string $switchPath, string $workflowPath): ?string
    {
        $own = array_filter(
            array_intersect_key($legacy, array_flip([$switchPath, $workflowPath])),
            static fn (?string $value): bool => $value !== null
        );
        if (!$own) {
            return null;
        }
        $resolved = $own + $inherited;

        return $this->flag($resolved, $switchPath) && (int)($resolved[$workflowPath] ?? 0) > 0 ? '1' : '0';
    }

    /**
     * Whether an automation's 2.8.x switch is on at a website with no
     * Autoresponder ID and Enable Module is not No there, each value the
     * website's own, else the inherited one.
     *
     * @param array<string, string|null> $legacy
     * @param array<string, string|null> $inherited
     */
    private function onWithoutWorkflow(
        array $legacy,
        array $inherited,
        string $switchPath,
        string $workflowPath
    ): bool {
        $resolved = array_filter($legacy, static fn (?string $value): bool => $value !== null) + $inherited;

        return $this->moduleSwitch($resolved) !== false
            && $this->flag($resolved, $switchPath)
            && (int)($resolved[$workflowPath] ?? 0) <= 0;
    }

    /**
     * @param array<string, string|null> $legacy
     */
    private function flag(array $legacy, string $key): bool
    {
        return in_array((string)($legacy[$key] ?? ''), ['1', 'true', 'yes'], true);
    }
}
