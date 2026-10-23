<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Engine;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Engine\Disconnector;

/**
 * CON-220: Disconnect and removal tell the engine that the connection ends
 * (contract v1.18.0 §16). The engine pauses a store with no connection
 * left and deletes nothing; a refused key or an engine that does not answer
 * is an outcome, never an exception, so neither can block the caller.
 */
class DisconnectorTest extends TestCase
{
    private const MAPPED = 'https://intelligence.smaily.com/api/v1/store/uninstall';

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    public function testTheMappedEndpointIsCalledWithTheConnectionsOwnKeyAndNoBody(): void
    {
        $disconnector = $this->disconnector([$this->ok('store_paused')]);

        $outcome = $disconnector->endConnection(
            'sk_live_key',
            ['store_uninstall' => self::MAPPED, 'ingest_ping' => 'https://intelligence.smaily.com/api/v1/ingest/ping'],
            'https://intelligence.smaily.com'
        );

        self::assertSame(Disconnector::PAUSED, $outcome);
        self::assertCount(1, $this->history);
        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::MAPPED, (string)$request->getUri());
        self::assertSame('Bearer sk_live_key', $request->getHeaderLine('Authorization'));
        self::assertSame('', (string)$request->getBody());
    }

    public function testAConnectionWithoutTheMapKeyUsesTheFallbackPath(): void
    {
        $disconnector = $this->disconnector([$this->ok('store_paused')]);

        $disconnector->endConnection('sk_live_key', [], 'https://intelligence.smaily.com/');

        self::assertSame(self::MAPPED, (string)$this->history[0]['request']->getUri());
    }

    public function testAnotherConnectionRemainingIsDone(): void
    {
        $outcome = $this->disconnector([$this->ok('connection_removed')])
            ->endConnection('sk_live_key', ['store_uninstall' => self::MAPPED], '');

        self::assertSame(Disconnector::CONNECTION_REMOVED, $outcome);
    }

    /**
     * A v1.17.0 engine answered `deletion_scheduled`; every 200 means done.
     */
    public function testAnotherStatusOn200IsDone(): void
    {
        $outcome = $this->disconnector([$this->ok('deletion_scheduled')])
            ->endConnection('sk_live_key', ['store_uninstall' => self::MAPPED], '');

        self::assertSame(Disconnector::ENDED, $outcome);
    }

    public function testARefusedKeyMeansTheConnectionHadAlreadyEnded(): void
    {
        $outcome = $this->disconnector([new Response(401, [], '{"error":"unauthorised"}')])
            ->endConnection('sk_live_key', ['store_uninstall' => self::MAPPED], '');

        self::assertSame(Disconnector::KEY_REFUSED, $outcome);
    }

    public function testOneAttemptOfAtMostThreeSecondsWithoutRedirects(): void
    {
        $options = [];
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            function () use (&$options): HttpClient {
                $handler = function (RequestInterface $request, array $requestOptions) use (&$options): Response {
                    $options = $requestOptions;

                    return $this->ok('store_paused');
                };

                return new HttpClient(['handler' => HandlerStack::create(new MockHandler([$handler]))]);
            }
        );

        (new Disconnector($factory))->endConnection('sk_live_key', ['store_uninstall' => self::MAPPED], '');

        self::assertSame(3, $options['timeout']);
        self::assertLessThanOrEqual(3, $options['connect_timeout']);
        self::assertFalse($options['allow_redirects']);
    }

    public function testAServerErrorIsOneAttemptAndNotTold(): void
    {
        $outcome = $this->disconnector([new Response(503), $this->ok('store_paused')])
            ->endConnection('sk_live_key', ['store_uninstall' => self::MAPPED], '');

        self::assertSame(Disconnector::NOT_TOLD, $outcome);
        self::assertCount(1, $this->history, 'A removal never waits for a retry.');
    }

    public function testAnUnreachableEngineIsNotTold(): void
    {
        $outcome = $this->disconnector([new ConnectException('Connection refused', new Request('POST', self::MAPPED))])
            ->endConnection('sk_live_key', ['store_uninstall' => self::MAPPED], '');

        self::assertSame(Disconnector::NOT_TOLD, $outcome);
    }

    public function testNothingIsSentWithoutAKeyOrAnAddress(): void
    {
        $disconnector = $this->disconnector([]);

        self::assertSame(
            Disconnector::NOT_TOLD,
            $disconnector->endConnection('', ['store_uninstall' => self::MAPPED], '')
        );
        self::assertSame(Disconnector::NOT_TOLD, $disconnector->endConnection('sk_live_key', [], ''));
        self::assertSame([], $this->history);
    }

    private function ok(string $status): Response
    {
        return new Response(200, [], (string)json_encode([
            'ok' => true,
            'status' => $status,
            'other_connections_remain' => $status === 'connection_removed',
        ]));
    }

    /**
     * @param array<int, Response|\Throwable> $responses
     */
    private function disconnector(array $responses): Disconnector
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $config = []): HttpClient => new HttpClient(['handler' => $stack] + $config)
        );

        return new Disconnector($factory);
    }
}
