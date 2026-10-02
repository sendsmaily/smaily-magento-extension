<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Client;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\Client\SmailyClient;
use Smaily\Connect\Model\Client\VerifiedCredentials;
use Smaily\Connect\Model\Logger\Logger;

class SmailyClientTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    /** @var VerifiedCredentials&\PHPUnit\Framework\MockObject\MockObject */
    private $verifiedCredentials;

    public function testGetReturnsDecodedBody(): void
    {
        $client = $this->createClient([new Response(200, [], '[{"id": 7, "title": "Welcome"}]')]);

        $result = $client->get(SmailyClient::ENDPOINT_AUTORESPONDER, ['status' => 'ACTIVE']);

        self::assertSame([['id' => 7, 'title' => 'Welcome']], $result);
        $request = $this->request(0);
        self::assertSame('/api/autoresponder.php', $request->getUri()->getPath());
        self::assertSame('status=ACTIVE', $request->getUri()->getQuery());
        self::assertSame('demo.sendsmaily.net', $request->getUri()->getHost());
    }

    public function testGetAutomationWorkflowsMapsIdAndTitle(): void
    {
        $client = $this->createClient([
            new Response(200, [], '[{"id": "7", "title": "Welcome"}, {"id": 9, "name": "Cart"}]'),
        ]);

        self::assertSame(
            [['id' => 7, 'title' => 'Welcome'], ['id' => 9, 'title' => 'Cart']],
            $client->getAutomationWorkflows()
        );
        // Only API-triggerable workflows may be offered: POST autoresponder.php
        // rejects every non-form_submitted workflow with code 221.
        $request = $this->request(0);
        self::assertSame('/api/workflows.php', $request->getUri()->getPath());
        self::assertSame('trigger_type=form_submitted', $request->getUri()->getQuery());
    }

    public function testGetAutomationWorkflowsFiltersDisabledWorkflows(): void
    {
        $client = $this->createClient([
            new Response(200, [], '[
                {"id": 3, "trigger_type": "form_submitted", "title": "Cart", "is_enabled": true},
                {"id": 9, "trigger_type": "form_submitted", "title": "Draft", "is_enabled": false}
            ]'),
        ]);

        self::assertSame([['id' => 3, 'title' => 'Cart']], $client->getAutomationWorkflows());
    }

    public function testPostSendsJsonAndAcceptsSuccessEnvelope(): void
    {
        $client = $this->createClient([new Response(200, [], '{"code": 101, "message": "OK"}')]);

        $result = $client->post(SmailyClient::ENDPOINT_CONTACT, [['email' => 'test@example.com']]);

        self::assertSame(101, $result['code']);
        $request = $this->request(0);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/contact.php', $request->getUri()->getPath());
        self::assertSame('[{"email":"test@example.com"}]', (string)$request->getBody());
    }

    public function testNonSuccessEnvelopeThrowsApiException(): void
    {
        $client = $this->createClient([new Response(200, [], '{"code": 203, "message": "Invalid data"}')]);

        try {
            $client->post(SmailyClient::ENDPOINT_CONTACT, []);
            self::fail('Expected ApiException');
        } catch (ApiException $exception) {
            self::assertSame(ApiException::CODE_INVALID_DATA, $exception->getSmailyCode());
            self::assertSame(203, $exception->getResponse()['code']);
        }
    }

    public function testHttp401ThrowsAuthenticationException(): void
    {
        $client = $this->createClient([new Response(401, [], 'Unauthorized')]);

        $this->expectException(AuthenticationException::class);
        $client->validateCredentials();
    }

    public function testAPassedCredentialCheckIsRemembered(): void
    {
        $client = $this->createClient([new Response(200, [], '[]')]);
        $this->verifiedCredentials->expects(self::once())->method('accept')->with('demo', 'user', 'secret');
        $this->verifiedCredentials->expects(self::never())->method('refuse');

        $client->validateCredentials();
    }

    /**
     * PRO-3560: a refusal seen anywhere — the queue's sends included — takes
     * the "Connected" status away from these credentials.
     */
    public function testAnyAuthenticationRefusalIsRemembered(): void
    {
        $client = $this->createClient([new Response(403, [], 'Forbidden')]);
        $this->verifiedCredentials->expects(self::once())->method('refuse')->with('demo', 'user', 'secret');
        $this->verifiedCredentials->expects(self::never())->method('accept');

        $this->expectException(AuthenticationException::class);
        $client->post(SmailyClient::ENDPOINT_CONTACT, []);
    }

    public function testAnOutageNeitherAcceptsNorRefusesTheCredentials(): void
    {
        $client = $this->createClient([new Response(503, [], 'Unavailable')]);
        $this->verifiedCredentials->expects(self::never())->method('accept');
        $this->verifiedCredentials->expects(self::never())->method('refuse');

        $this->expectException(TransportException::class);
        $client->validateCredentials();
    }

    public function testHttp500ThrowsTransportExceptionWithStatus(): void
    {
        $client = $this->createClient([new Response(500, [], 'Server error')]);

        try {
            $client->get(SmailyClient::ENDPOINT_CONTACT);
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertSame(500, $exception->getHttpStatus());
        }
    }

    public function testHttp429CarriesTheRequestedRetryDelay(): void
    {
        $client = $this->createClient([new Response(429, ['Retry-After' => '90'], 'Slow down')]);

        try {
            $client->post(SmailyClient::ENDPOINT_CONTACT, []);
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertSame(429, $exception->getHttpStatus());
            self::assertSame(90, $exception->getRetryAfter());
        }
    }

    public function testAnHttpDateRetryAfterIsNotMisparsed(): void
    {
        // Smaily sends the delta-seconds form; a date leaves the queue on its
        // own backoff rather than being read as a number of seconds.
        $client = $this->createClient([
            new Response(429, ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT'], 'Slow down'),
        ]);

        try {
            $client->post(SmailyClient::ENDPOINT_CONTACT, []);
            self::fail('Expected TransportException');
        } catch (TransportException $exception) {
            self::assertNull($exception->getRetryAfter());
        }
    }

    public function testMalformedBodyThrowsTransportException(): void
    {
        $client = $this->createClient([new Response(200, [], 'not json')]);

        $this->expectException(TransportException::class);
        $client->get(SmailyClient::ENDPOINT_CONTACT);
    }

    /**
     * @param array<int, Response> $responses
     */
    private function createClient(array $responses): SmailyClient
    {
        $this->history = [];
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(Middleware::history($this->history));

        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $config) use ($handlerStack) {
                $config['handler'] = $handlerStack;
                return new HttpClient($config);
            }
        );

        $this->verifiedCredentials = $this->createMock(VerifiedCredentials::class);

        return new SmailyClient(
            $factory,
            $this->createMock(Logger::class),
            $this->verifiedCredentials,
            'demo',
            'user',
            'secret'
        );
    }

    private function request(int $index): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history, 'Expected a recorded HTTP request');

        return $this->history[$index]['request'];
    }
}
