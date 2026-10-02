<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Client;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
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

    /** @var array<int, array{level: string, message: string, context: array<string, mixed>}> */
    private array $logged = [];

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

    /**
     * PRO-3579: Smaily answers 403 code 227 when the account's package has no
     * API access — before it checks the credentials. That is not a credential
     * refusal: the credentials are not marked refused, and the exception says
     * what is wrong.
     */
    public function testAPackageWithoutApiAccessIsNotACredentialRefusal(): void
    {
        $client = $this->createClient([
            new Response(403, [], '{"code":227,"message":"A paid package is required."}'),
        ]);
        $this->verifiedCredentials->expects(self::once())->method('planBlocked')->with('demo', 'user', 'secret');
        $this->verifiedCredentials->expects(self::never())->method('refuse');
        $this->verifiedCredentials->expects(self::never())->method('accept');

        try {
            $client->validateCredentials();
            self::fail('Expected PlanBlockedException');
        } catch (PlanBlockedException $exception) {
            self::assertSame(403, $exception->getHttpStatus());
            self::assertStringContainsString('package does not include API access', $exception->getMessage());
        }
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

    /**
     * PRO-3572: Guzzle's network-failure message ends with the full request
     * URL, and the contact lookup puts the address in the query string.
     */
    public function testANetworkFailureLogsTheEndpointButNoQueryValue(): void
    {
        $client = $this->createClient([self::networkFailure()]);

        try {
            $client->get(SmailyClient::ENDPOINT_CONTACT, ['email' => 'person@example.com']);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            self::assertSame(0, $exception->getHttpStatus());
        }

        $errors = array_values(array_filter($this->logged, static fn (array $entry) => $entry['level'] === 'error'));
        self::assertCount(1, $errors);
        $line = json_encode($errors[0], JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('cURL error 28', (string)$line);
        self::assertStringContainsString('https://demo.sendsmaily.net/api/contact.php', (string)$line);
        $this->assertCarriesNoContact((string)$line);
    }

    public function testANetworkFailureLeavesNoQueryValueAtDebugLevelOrInTheException(): void
    {
        $client = $this->createClient([self::networkFailure()]);

        try {
            $client->get(SmailyClient::ENDPOINT_CONTACT, ['email' => 'person@example.com']);
            self::fail('Expected a TransportException');
        } catch (TransportException $exception) {
            // Callers log, store and display this message (queue last_error,
            // the admin Log, the debug log of the consent lookup).
            self::assertStringContainsString('cURL error 28', $exception->getMessage());
            for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
                $this->assertCarriesNoContact($link->getMessage());
            }
        }

        $this->assertCarriesNoContact((string)json_encode($this->logged, JSON_UNESCAPED_SLASHES));
    }

    public function testTheLastExchangeHoldsTheBodyAsPostedAndTheReply(): void
    {
        $client = $this->createClient([new Response(200, [], '{"code": 101, "message": "OK"}')]);
        self::assertNull($client->lastExchange(), 'Nothing was sent yet');

        $client->post(SmailyClient::ENDPOINT_CONTACT, [['email' => 'test@example.com']]);

        self::assertSame([
            'request' => [['email' => 'test@example.com']],
            'response' => ['http_status' => 200, 'body' => ['code' => 101, 'message' => 'OK']],
        ], $client->lastExchange());
    }

    public function testARefusalIsRecordedAsTheServerAnsweredIt(): void
    {
        $client = $this->createClient([
            new Response(200, [], '{"code": 203, "message": "Invalid data"}'),
            new Response(502, [], '<html>Bad gateway</html>'),
        ]);

        try {
            $client->post(SmailyClient::ENDPOINT_CONTACT, [['email' => 'test@example.com']]);
        } catch (ApiException) {
        }
        self::assertSame(
            ['http_status' => 200, 'body' => ['code' => 203, 'message' => 'Invalid data']],
            $client->lastExchange()['response'] ?? null
        );

        try {
            $client->post(SmailyClient::ENDPOINT_AUTORESPONDER, ['autoresponder' => 7]);
        } catch (TransportException) {
        }
        self::assertSame([
            'request' => ['autoresponder' => 7],
            'response' => ['http_status' => 502, 'body' => '<html>Bad gateway</html>'],
        ], $client->lastExchange());
    }

    public function testARequestThatGotNoAnswerRecordsNoReplyAndNoCredentials(): void
    {
        $client = $this->createClient([self::networkFailure()]);

        try {
            $client->post(SmailyClient::ENDPOINT_CONTACT, [['email' => 'test@example.com']]);
        } catch (TransportException) {
        }

        self::assertSame(['request' => [['email' => 'test@example.com']], 'response' => null], $client->lastExchange());
        self::assertStringNotContainsString('secret', (string)json_encode($client->lastExchange()));
    }

    public function testMalformedBodyThrowsTransportException(): void
    {
        $client = $this->createClient([new Response(200, [], 'not json')]);

        $this->expectException(TransportException::class);
        $client->get(SmailyClient::ENDPOINT_CONTACT);
    }

    /**
     * @param array<int, Response|callable> $responses
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

        $this->logged = [];
        $logger = $this->createMock(Logger::class);
        foreach (['error', 'info', 'debug'] as $level) {
            $logger->method($level)->willReturnCallback(
                function (string $message, array $context = []) use ($level): void {
                    $this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
                }
            );
        }

        return new SmailyClient(
            $factory,
            $logger,
            $this->verifiedCredentials,
            'demo',
            'user',
            'secret'
        );
    }

    /**
     * A connect timeout worded exactly as Guzzle's curl handler words it.
     */
    private static function networkFailure(): callable
    {
        return static fn (RequestInterface $request) => new ConnectException(
            'cURL error 28: Connection timed out after 10001 milliseconds '
            . '(see https://curl.se/libcurl/c/libcurl-errors.html) for ' . $request->getUri(),
            $request
        );
    }

    private function assertCarriesNoContact(string $text): void
    {
        self::assertStringNotContainsString('person@example.com', $text);
        self::assertStringNotContainsString('person%40example.com', $text);
        self::assertStringNotContainsString('person', $text);
        self::assertStringNotContainsString('email=', $text);
    }

    private function request(int $index): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history, 'Expected a recorded HTTP request');

        return $this->history[$index]['request'];
    }
}
