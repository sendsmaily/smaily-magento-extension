<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

use Smaily\Connect\Model\Adminhtml\WebsiteOverrides;
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
 * A website of a 2+-website install that uses its own Smaily account
 * (WebsiteOverrides::usesOwnAccount()) has only its own automations
 * (CON-190): its own rows and the workflows saved at its website scope. The
 * store-wide rows (website 0) and the default-scope workflows were picked
 * from the store-wide account, so they resolve to nothing there — also
 * where the website holds no automations of its own yet (an install from
 * before CON-190): it starts nothing until its workflows are picked.
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
    /** Each trigger's workflow in system config. */
    private const WORKFLOW_PATHS = [
        Trigger::WELCOME => Config::XML_PATH_WELCOME_WORKFLOW,
        Trigger::FIRST_ORDER => Config::XML_PATH_FIRST_ORDER_WORKFLOW,
        Trigger::ABANDONED_CART => Config::XML_PATH_ABANDONED_WORKFLOW,
    ];

    public function __construct(
        private readonly Config $config,
        private readonly CollectionFactory $mappingCollectionFactory,
        private readonly WebsiteOverrides $overrides
    ) {
    }

    public function resolve(string $trigger, int $websiteId, string $language): ?WorkflowMatch
    {
        $mode = $this->config->getMultilingualMode($websiteId);
        $ownAccount = $this->overrides->usesOwnAccount($websiteId);

        if ($mode === MultilingualMode::MODE_SINGLE || $mode === MultilingualMode::MODE_SINGLE_WORKFLOW) {
            return $this->configDefault($trigger, $websiteId, $ownAccount);
        }

        $effectiveLanguage = $language !== '' ? $language : Mapping::LANGUAGE_DEFAULT;
        // The website's own rows win over the store-wide rows (website 0),
        // which a website with its own Smaily account does not read.
        $websiteIds = $ownAccount ? [$websiteId] : [$websiteId, 0];

        $match = $this->findMapping($trigger, $websiteIds, $effectiveLanguage, $mode)
            ?? $this->findFallbackMapping($trigger, $websiteIds, $mode);
        if ($match !== null) {
            return $match;
        }
        if ($mode !== MultilingualMode::MODE_PER_LANGUAGE_ACCOUNTS) {
            return $this->configDefault($trigger, $websiteId, $ownAccount);
        }

        // Mode A: the default fallback account's own row for the trigger.
        $fallbackLanguage = $this->config->getFallbackLanguage($websiteId);
        if ($fallbackLanguage === '' || $fallbackLanguage === $effectiveLanguage) {
            return null;
        }

        return $this->findMapping($trigger, $websiteIds, $fallbackLanguage, $mode);
    }

    /**
     * @param int[] $websiteIds
     */
    private function findMapping(string $trigger, array $websiteIds, string $language, string $mode): ?WorkflowMatch
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['in' => $websiteIds])
            ->addFieldToFilter('trigger_type', $trigger)
            ->addFieldToFilter('language', $language)
            ->addModeFilter($mode)
            ->setOrder('website_id', 'DESC')
            ->setPageSize(1);

        return $this->toMatch($collection->getFirstItem());
    }

    /**
     * @param int[] $websiteIds
     */
    private function findFallbackMapping(string $trigger, array $websiteIds, string $mode): ?WorkflowMatch
    {
        $collection = $this->mappingCollectionFactory->create();
        $collection->addFieldToFilter('website_id', ['in' => $websiteIds])
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

    /**
     * The trigger's workflow saved in system config: for a website that uses
     * its own Smaily account only the one saved at its website scope.
     */
    private function configDefault(string $trigger, int $websiteId, bool $ownAccount): ?WorkflowMatch
    {
        $workflowId = $ownAccount
            ? (int)$this->overrides->ownValue(
                WebsiteOverrides::AUTOMATIONS,
                $websiteId,
                self::WORKFLOW_PATHS[$trigger] ?? ''
            )
            : match ($trigger) {
                Trigger::WELCOME => $this->config->getWelcomeWorkflow($websiteId),
                Trigger::FIRST_ORDER => $this->config->getFirstOrderWorkflow($websiteId),
                Trigger::ABANDONED_CART => $this->config->getAbandonedCartWorkflow($websiteId),
                default => 0,
            };

        return $workflowId > 0 ? new WorkflowMatch($workflowId, null) : null;
    }
}
