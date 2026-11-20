<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\Request\Http;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Backfill\ImportsOnConnect;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Client;
use Smaily\Connect\Model\Engine\Connections;
use Smaily\Connect\Model\Engine\Connector;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\SleeperInterface;
use Smaily\Connect\Model\Engine\Websites;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Report\ConnectionHealth;
use Smaily\Connect\Model\Report\InstallationReport;
use Smaily\Connect\Model\StorefrontScript;
use Smaily\Connect\Test\Integration\IntegrationTestCase;
use Smaily\Connect\Test\Integration\Support\Fake\DatabaseScopeConfig;

/**
 * CON-359 (contract v1.33.0 §1, §2) against a real core_config_data: the
 * connect step declares that the extension applies the connection prefix
 * and stores the prefix the engine answers; a disconnect keeps it, and the
 * next connect reports it as `connection.previous_prefix`, so a reconnect
 * keeps its prefix. A website's own connection holds a prefix of its own.
 */
class ConnectionPrefixReconnectTest extends IntegrationTestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function testAReconnectReportsAndKeepsThePrefixTheConnectionHad(): void
    {
        $settings = $this->settings();

        $this->connector($settings, [$this->exchange('c2-')])->connect('tok_first');

        $first = $this->sentBody(0);
        self::assertSame(['connection_prefix'], $first['capabilities']);
        self::assertArrayNotHasKey('connection', $first, 'A first connection reports no previous prefix');
        self::assertSame('c2-', $this->stored('default', 0));
        self::assertSame('c2-', $settings->getConnectionPrefix());

        $settings->clear();
        self::assertSame('', $settings->getConnectionPrefix(), 'Not connected');
        self::assertSame('c2-', $this->stored('default', 0), 'The disconnect keeps it');

        $this->history = [];
        $this->connector($settings, [$this->exchange('c2-')])->connect('tok_second');

        self::assertSame(['previous_prefix' => 'c2-'], $this->sentBody(0)['connection']);
        self::assertSame('c2-', $settings->getConnectionPrefix());
    }

    /**
     * A connection made before the prefix was stored is unprefixed: its
     * reconnect reports '' (contract §1: "`""` if it held an unprefixed
     * connection"), and an engine before v1.33.0 answers no prefix.
     */
    public function testAConnectionFromBeforeThePrefixReconnectsAsUnprefixed(): void
    {
        $settings = $this->settings();
        $this->connector($settings, [$this->exchange(null)])->connect('tok_first');
        $this->connection->delete('core_config_data', ['path = ?' => Settings::XML_PATH_CONNECTION_PREFIX]);

        $this->history = [];
        $this->connector($settings, [$this->exchange('')])->connect('tok_second');

        self::assertSame(['previous_prefix' => ''], $this->sentBody(0)['connection']);
        self::assertSame('', $settings->getConnectionPrefix());
    }

    /**
     * The ping's prefix wins over the stored one (contract §2).
     */
    public function testThePingsPrefixIsStored(): void
    {
        $settings = $this->settings();
        $this->connector($settings, [$this->exchange('c2-')])->connect('tok_first');

        $this->client($settings, [
            new Response(200, [], '{"ok":true,"pong":true,"config":{"connection":{"prefix":"c3-"}}}'),
        ])->ping();

        self::assertSame('c3-', $settings->getConnectionPrefix());
    }

    /**
     * CON-237: a website's own connection stores and reports its own
     * prefix, never the primary's its scope would fall back to.
     */
    public function testAWebsiteConnectionHoldsItsOwnPrefix(): void
    {
        $primary = $this->settings();
        $this->connector($primary, [$this->exchange('c1-')])->connect('tok_primary');
        $website = $primary->withWebsite(2);

        $this->history = [];
        $this->client($website, [$this->exchange('c4-')])->setupExchange('tok_website');
        self::assertArrayNotHasKey('connection', $this->sentBody(0), 'Not the primary\'s prefix');
        $website->storeExchange(json_decode((string)$this->exchange('c4-')->getBody(), true), 'shop-lv');

        self::assertSame('c4-', $website->getConnectionPrefix());
        self::assertSame('c4-', $this->stored('websites', 2));
        self::assertSame('c1-', $primary->getConnectionPrefix());
    }

    private function settings(): Settings
    {
        return $this->objectManager->create(Settings::class, [
            'scopeConfig' => new DatabaseScopeConfig($this->connection, []),
            'configWriter' => $this->objectManager->get(WriterInterface::class),
            'cacheTypeList' => $this->createMock(TypeListInterface::class),
        ]);
    }

    /**
     * @param Response[] $responses
     */
    private function connector(Settings $settings, array $responses): Connector
    {
        $connections = $this->createMock(Connections::class);
        $connections->method('installationAccount')->willReturn('shop');

        return new Connector(
            $this->client($settings, $responses),
            $settings,
            $this->createMock(ImportsOnConnect::class),
            $this->createMock(Websites::class),
            $connections,
            $this->createMock(InstallationReport::class)
        );
    }

    /**
     * The real engine client of these settings, its HTTP answered in turn.
     *
     * @param Response[] $responses
     */
    private function client(Settings $settings, array $responses): Client
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $config): HttpClient => new HttpClient(['handler' => $handlerStack] + $config)
        );
        $storeManager = $this->createMock(StoreManagerInterface::class);

        return new Client(
            $settings,
            $factory,
            $this->createMock(ProductMetadataInterface::class),
            $storeManager,
            $this->createMock(Logger::class),
            $this->createMock(SleeperInterface::class),
            new StorefrontScript($storeManager, $this->createMock(Http::class)),
            $this->createMock(ConnectionHealth::class)
        );
    }

    /**
     * A §1 answer with this connection prefix; null: an engine before v1.33.0.
     */
    private function exchange(?string $prefix): Response
    {
        $config = ['batch_size_max' => 100];
        if ($prefix !== null) {
            $config['connection'] = ['prefix' => $prefix];
        }

        return new Response(200, [], (string)json_encode([
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'tenant_name' => 'Shop',
            'smaily_subdomain' => 'shop',
            'api_key' => 'sk_8f3k2a4e1c4d8a9b2f7e3d1a6c8b9e0f',
            'engine_base_url' => 'https://intelligence.smaily.com',
            'engine_version' => '1.34.0',
            'endpoints' => ['ingest_ping' => 'https://intelligence.smaily.com/api/v1/ingest/ping'],
            'config' => $config,
            'issued_at' => '2026-11-20T10:15:23Z',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(int $index): array
    {
        return (array)json_decode((string)$this->history[$index]['request']->getBody(), true);
    }

    private function stored(string $scope, int $scopeId): string
    {
        return (string)$this->connection->fetchOne(
            $this->connection->select()->from('core_config_data', ['value'])
                ->where('scope = ?', $scope)
                ->where('scope_id = ?', $scopeId)
                ->where('path = ?', Settings::XML_PATH_CONNECTION_PREFIX)
        );
    }
}
