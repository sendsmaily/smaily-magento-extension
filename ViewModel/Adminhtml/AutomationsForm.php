<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel\Adminhtml;

use Magento\Framework\Locale\ResolverInterface as LocaleResolver;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\SmailyClientProvider;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Exception\EngineException;
use Smaily\Connect\Model\Engine\Settings;

/**
 * Engine-run automations form data (contract §11/§12): the trigger catalog
 * is sector-filtered and dynamic — rendered from the live response, never
 * hardcoded. The engine's stored config is the source of truth; the plugin
 * keeps no local copy.
 */
class AutomationsForm implements ArgumentInterface
{
    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    private ?string $error = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Client $client,
        private readonly SmailyClientProvider $smailyClientProvider,
        private readonly LocaleResolver $localeResolver
    ) {
    }

    public function isEngineConnected(): bool
    {
        return $this->settings->isConnected();
    }

    /**
     * Campaign Intelligence refuses the account (PRO-2451): the page explains
     * that instead of asking the engine (PRO-2465).
     */
    public function isEngineRefused(): bool
    {
        return $this->settings->isRefused();
    }

    public function getLoadError(): ?string
    {
        $this->load();

        return $this->error;
    }

    /**
     * Catalog triggers merged with the stored config.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRows(): array
    {
        $this->load();
        if ($this->catalog === null) {
            return [];
        }

        $configured = [];
        try {
            $config = $this->client->getAutomationsConfig();
            foreach ((array)($config['configs'] ?? []) as $row) {
                if (is_array($row) && isset($row['trigger_key'])) {
                    $configured[(string)$row['trigger_key']] = $row;
                }
            }
        } catch (EngineException) {
            // Fall through with defaults; the load error is already surfaced.
        }

        $rows = [];
        foreach ((array)($this->catalog['triggers'] ?? []) as $trigger) {
            if (!is_array($trigger) || empty($trigger['key'])) {
                continue;
            }
            $key = (string)$trigger['key'];
            $existing = $configured[$key] ?? [];
            $map = (array)($existing['automation_map'] ?? []);
            $rows[] = [
                'key' => $key,
                'name' => $this->localized($trigger, 'name', $key),
                'description' => $this->localized($trigger, 'description', ''),
                'recipe' => (string)($trigger['recipe_en'] ?? $trigger['recipe_et'] ?? ''),
                // Fail-closed defaults per contract §13.
                'enabled' => (bool)($existing['enabled'] ?? false),
                // A per_language map stores the single-mode id under
                // "fallback" — read both so mixed-platform tenants render.
                'workflow_id' => (string)($map['id'] ?? $map['fallback'] ?? ''),
                'language_mode' => (string)($existing['language_mode'] ?? 'single'),
                'original_map' => json_encode($map) ?: '{}',
                'cooldown_days' => (int)($existing['cooldown_days'] ?? 7),
                'daily_cap' => $existing['daily_cap'] ?? null,
                'test_mode' => (bool)($existing['test_mode'] ?? true),
                'test_emails' => implode(', ', (array)($existing['test_emails'] ?? [])),
            ];
        }

        return $rows;
    }

    /**
     * Smaily workflows for the mapping dropdowns.
     *
     * @return array<int, array{id: int, title: string}>
     */
    public function getWorkflows(): array
    {
        try {
            return $this->smailyClientProvider->forStore(null)->getAutomationWorkflows();
        } catch (SmailyClientException) {
            return [];
        }
    }

    /**
     * Pick a catalog field in the current admin locale, `_en` fallback.
     *
     * The engine catalog carries `<field>_<lang>` pairs (e.g. name_et); an
     * unknown locale or a missing/blank localized field yields the `_en`
     * value so titles never render empty.
     *
     * @param array<string, mixed> $trigger
     */
    private function localized(array $trigger, string $field, string $default): string
    {
        $lang = $this->localeLanguage();
        if ($lang !== '' && $lang !== 'en') {
            $value = trim((string)($trigger[$field . '_' . $lang] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $fallback = trim((string)($trigger[$field . '_en'] ?? ''));

        return $fallback !== '' ? $fallback : $default;
    }

    /**
     * The 2-letter (ISO 639-1) code of the resolved admin locale — 'et' for
     * et_EE. An unrecognizable locale yields '' (treated as English).
     */
    private function localeLanguage(): string
    {
        $locale = (string)$this->localeResolver->getLocale();
        $language = strtolower(strtok($locale, '_') ?: '');

        return preg_match('/^[a-z]{2,3}$/', $language) === 1 ? $language : '';
    }

    private function load(): void
    {
        if ($this->catalog !== null
            || $this->error !== null
            || !$this->isEngineConnected()
            || $this->isEngineRefused()
        ) {
            return;
        }

        try {
            $this->catalog = $this->client->automationsCatalog();
        } catch (EngineException $exception) {
            $this->error = $exception->getMessage();
        }
    }
}
