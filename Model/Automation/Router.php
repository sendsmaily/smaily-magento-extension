<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\MultilingualMode;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory;

/**
 * Resolves the Smaily workflow AND account for a (trigger, website,
 * language) tuple.
 *
 * Mirrors the WooCommerce plugin's Multilingual\Router semantics: modes
 * "single" and "c" collapse every language into the default bucket (one
 * workflow per trigger, configured in system config); modes "a" and "b" look
 * up per-language rows in smaily_automation_mapping, falling back to the
 * trigger's default-fallback row. Mode "b" then falls back to the config
 * default. Mode "a" never does (CON-188): the config default was picked from
 * no per-language account, so the handler would post it through the event
 * store view's language account. Mode "a" falls back instead to the row of
 * the website's fallback language (the default fallback account's own
 * workflow), and with none there resolves to nothing.
 *
 * Mapping rows are scoped by website: a row for the event's website wins
 * over a global row (website_id 0, written by the Settings/wizard mapping
 * editor and the 2.8.x migration's default-scope seeding). Only the rows of
 * the active mode's accounts are read (Collection::addModeFilter(),
 * CON-184): after a website leaves mode A for mode B, its per-language
 * accounts' workflow ids stay in the table but are never posted through the
 * website's one account, and the other way round.
 *
 * A matched row's account_key travels with the workflow (WorkflowMatch), so
 * the dispatcher posts the workflow through the account the row names — a
 * mode-A fallback row must never fire another account's workflow ID through
 * the store view's credentials (Woo AutomationRouter parity). Config-default
 * resolutions (modes single, b and c) carry no account key: credentials
 * follow the event's store view as before.
 *
 * Returns null when no workflow is mapped — callers treat that as a
 * terminal skip (retrying cannot make a mapping appear).
 */
class Router
{
    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $mappingCollectionFactory
    ) {
    }

    public function resolve(string $trigger, int $websiteId, string $language): ?WorkflowMatch
    {
        $mode = $this->config->getMultilingualMode($websiteId);

        if ($mode === MultilingualMode::MODE_SINGLE || $mode === MultilingualMode::MODE_SINGLE_WORKFLOW) {
            return $this->configDefault($trigger, $websiteId);
        }

        $effectiveLanguage = $language !== '' ? $language : Mapping::LANGUAGE_DEFAULT;

        $match = $this->findMapping($trigger, $websiteId, $effectiveLanguage, $mode)
            ?? $this->findFallbackMapping($trigger, $websiteId, $mode);
        if ($match !== null) {
            return $match;
        }
        if ($mode !== MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
            return $this->configDefault($trigger, $websiteId);
        }

        // Mode A: the default fallback account's own row for the trigger.
        $fallbackLanguage = $this->config->getFallbackLanguage($websiteId);
        if ($fallbackLanguage === '' || $fallbackLanguage === $effectiveLanguage) {
            return null;
        }

        return $this->findMapping($trigger, $websiteId, $fallbackLanguage, $mode);
    }

    private function findMapping(string $trigger, int $websiteId, string $language, string $mode): ?WorkflowMatch
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['in' => [$websiteId, 0]])
            ->addFieldToFilter('trigger_type', $trigger)
            ->addFieldToFilter('language', $language)
            ->addModeFilter($mode)
            ->setOrder('website_id', 'DESC')
            ->setPageSize(1);

        return $this->toMatch($collection->getFirstItem());
    }

    private function findFallbackMapping(string $trigger, int $websiteId, string $mode): ?WorkflowMatch
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['in' => [$websiteId, 0]])
            ->addFieldToFilter('trigger_type', $trigger)
            ->addFieldToFilter('is_default_fallback', ['eq' => 1])
            ->addModeFilter($mode)
            ->setOrder('website_id', 'DESC')
            ->setPageSize(1);

        return $this->toMatch($collection->getFirstItem());
    }

    private function toMatch(mixed $mapping): ?WorkflowMatch
    {
        if (!$mapping instanceof Mapping || $mapping->getWorkflowId() <= 0) {
            return null;
        }

        return new WorkflowMatch($mapping->getWorkflowId(), $mapping->getAccountKey());
    }

    private function configDefault(string $trigger, int $websiteId): ?WorkflowMatch
    {
        $workflowId = match ($trigger) {
            Trigger::WELCOME => $this->config->getWelcomeWorkflow($websiteId),
            Trigger::FIRST_ORDER => $this->config->getFirstOrderWorkflow($websiteId),
            Trigger::ABANDONED_CART => $this->config->getAbandonedCartWorkflow($websiteId),
            default => 0,
        };

        return $workflowId > 0 ? new WorkflowMatch($workflowId, null) : null;
    }
}
