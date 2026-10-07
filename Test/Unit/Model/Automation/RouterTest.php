<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Automation;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Automation\Mapping;
use Smaily\Connect\Model\Automation\Router;
use Smaily\Connect\Model\Automation\Trigger;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\Collection;
use Smaily\Connect\Model\ResourceModel\Automation\Mapping\CollectionFactory;

/**
 * The multilingual routing matrix (Woo Multilingual\Router parity): modes
 * single/c collapse to the config default; modes a/b resolve exact-language
 * mapping rows, then the default-fallback row, then the config default; a
 * matched row's account_key travels with the workflow so mode-A fallback
 * rows fire through the account they name.
 */
class RouterTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private Config $config;

    /**
     * Queued collection results: each Router lookup consumes one.
     *
     * @var array<int, Mapping|null>
     */
    private array $lookupResults = [];

    /**
     * Filters recorded per lookup, in call order.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $recordedFilters = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->lookupResults = [];
        $this->recordedFilters = [];
    }

    public function testSingleModeUsesConfigDefaultWithoutTouchingMappings(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('single');
        $this->config->method('getWelcomeWorkflow')->willReturn(11);

        $match = $this->router()->resolve(Trigger::WELCOME, 1, 'et');

        self::assertNotNull($match);
        self::assertSame(11, $match->workflowId);
        self::assertNull($match->accountKey, 'Config defaults carry no account key');
        self::assertCount(0, $this->recordedFilters, 'Modes single/c never query the mapping table');
    }

    public function testModeCIgnoresLanguageAndUsesConfigDefault(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('c');
        $this->config->method('getAbandonedCartWorkflow')->willReturn(33);

        $match = $this->router()->resolve(Trigger::ABANDONED_CART, 2, 'de');

        self::assertNotNull($match);
        self::assertSame(33, $match->workflowId);
        self::assertNull($match->accountKey);
        self::assertCount(0, $this->recordedFilters);
    }

    public function testModeAExactLanguageRowCarriesItsAccountKey(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('a');
        $this->lookupResults = [$this->mappingRow(77, 'et')];

        $match = $this->router()->resolve(Trigger::WELCOME, 1, 'et');

        self::assertNotNull($match);
        self::assertSame(77, $match->workflowId);
        self::assertSame('et', $match->accountKey, 'Dispatch must follow the row account, not the store view');
        self::assertSame('et', $this->recordedFilters[0]['language']);
    }

    public function testModeAFallbackRowCarriesTheFallbackAccountKey(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('a');
        // No exact row for 'ru'; the default-fallback row belongs to the EN account.
        $this->lookupResults = [null, $this->mappingRow(88, 'en')];

        $match = $this->router()->resolve(Trigger::FIRST_ORDER, 1, 'ru');

        self::assertNotNull($match);
        self::assertSame(88, $match->workflowId);
        self::assertSame('en', $match->accountKey, 'A fallback row must fire through its own account');
        self::assertSame(1, $this->recordedFilters[1]['is_default_fallback']);
    }

    public function testModeBExactRowUsesDefaultAccount(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('b');
        $this->lookupResults = [$this->mappingRow(55, 'default')];

        $match = $this->router()->resolve(Trigger::WELCOME, 1, 'et');

        self::assertNotNull($match);
        self::assertSame(55, $match->workflowId);
        self::assertSame('default', $match->accountKey);
    }

    public function testEmptyLanguageLooksUpTheDefaultBucket(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('b');
        $this->lookupResults = [$this->mappingRow(44, 'default')];

        $match = $this->router()->resolve(Trigger::WELCOME, 1, '');

        self::assertNotNull($match);
        self::assertSame('default', $this->recordedFilters[0]['language']);
    }

    public function testMappingModesFallBackToConfigDefaultWithoutAccountKey(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('b');
        $this->config->method('getWelcomeWorkflow')->willReturn(12);
        $this->lookupResults = [null, null];

        $match = $this->router()->resolve(Trigger::WELCOME, 1, 'et');

        self::assertNotNull($match);
        self::assertSame(12, $match->workflowId);
        self::assertNull($match->accountKey, 'Config-default credentials keep following the store view');
    }

    public function testNoMappingAnywhereIsATerminalSkip(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('a');
        $this->config->method('getWelcomeWorkflow')->willReturn(0);
        $this->lookupResults = [null, null];

        self::assertNull($this->router()->resolve(Trigger::WELCOME, 1, 'et'));
    }

    public function testUnknownTriggerIsATerminalSkip(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('single');

        self::assertNull($this->router()->resolve('mystery_event', 1, 'et'));
    }

    public function testLookupsIncludeGlobalRowsAndPreferTheWebsite(): void
    {
        $this->config->method('getMultilingualMode')->willReturn('b');
        $this->lookupResults = [$this->mappingRow(55, 'default')];

        $this->router()->resolve(Trigger::WELCOME, 3, 'et');

        self::assertSame(
            ['in' => [3, 0]],
            $this->recordedFilters[0]['website_id'],
            'The Settings editor writes global rows (website 0); a website-specific row must still win'
        );
    }

    private function router(): Router
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturnCallback(function (): Collection {
            $index = count($this->recordedFilters);
            $this->recordedFilters[$index] = [];
            $item = $this->lookupResults[$index] ?? null;

            $collection = $this->createMock(Collection::class);
            $collection->method('addFieldToFilter')->willReturnCallback(
                function (string $field, mixed $condition) use ($collection, $index): Collection {
                    $this->recordedFilters[$index][$field] = is_array($condition) && isset($condition['eq'])
                        ? $condition['eq']
                        : $condition;

                    return $collection;
                }
            );
            $collection->method('setOrder')->willReturnSelf();
            $collection->method('setPageSize')->willReturnSelf();
            $collection->method('getFirstItem')->willReturn($item ?? $this->emptyMapping());

            return $collection;
        });

        return new Router($this->config, $factory);
    }

    private function mappingRow(int $workflowId, string $accountKey): Mapping
    {
        $mapping = $this->createMock(Mapping::class);
        $mapping->method('getWorkflowId')->willReturn($workflowId);
        $mapping->method('getAccountKey')->willReturn($accountKey);

        return $mapping;
    }

    private function emptyMapping(): Mapping
    {
        return $this->mappingRow(0, 'default');
    }
}
