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
use Smaily\Connect\Model\Engine\Onboarding;

/**
 * CON-363: the two key-less §21 calls behind "Try Intelligence free". Each
 * is one attempt to the engine's own host with no API key and no redirect —
 * the start's body carries the Smaily API password — and neither throws:
 * the status is false on any doubt, and the start answers its status and
 * body for IntelligenceStart to map.
 */
class OnboardingTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function testTheStatusIsAskedWithoutAKeyAndIsTrueOnlyForStartOpenTrue(): void
    {
        self::assertTrue($this->onboarding([$this->json(200, ['start_open' => true])])->isStartOpen());

        $request = $this->history[0]['request'];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://intelligence.smaily.com/api/v1/onboarding/status', (string)$request->getUri());
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame(3, $this->history[0]['options']['timeout']);
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    /**
     * @dataProvider closedAnswers
     */
    public function testAnyOtherStatusAnswerIsClosed(Response|\Exception $answer): void
    {
        self::assertFalse($this->onboarding([$answer])->isStartOpen());
        self::assertCount(1, $this->history, 'One attempt, no retry.');
    }

    /**
     * @return array<string, array{Response|\Exception}>
     */
    public static function closedAnswers(): array
    {
        return [
            'start_open false' => [new Response(200, [], '{"start_open": false}')],
            'start_open as a string' => [new Response(200, [], '{"start_open": "true"}')],
            'not JSON' => [new Response(200, [], 'open')],
            'server error' => [new Response(503, [], '{"start_open": true}')],
            'redirect' => [new Response(302, ['Location' => 'https://elsewhere.example/status'])],
            'no answer' => [new ConnectException('timed out', new Request('GET', '/'))],
        ];
    }

    public function testTheStartSendsTheBodyWithoutAKeyAndFollowsNoRedirect(): void
    {
        $onboarding = $this->onboarding([
            new Response(307, ['Location' => 'https://elsewhere.example/collect']),
            $this->json(200, ['start_url' => 'https://elsewhere.example/x']),
        ]);

        $answer = $onboarding->start(['smaily_subdomain' => 'shop', 'smaily_password' => 'secret-pass']);

        self::assertSame(307, $answer['status']);
        self::assertCount(1, $this->history, 'The password never follows a redirect to another host.');
        $request = $this->history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://intelligence.smaily.com/api/v1/onboarding/start', (string)$request->getUri());
        self::assertFalse($request->hasHeader('Authorization'));
        self::assertSame(
            ['smaily_subdomain' => 'shop', 'smaily_password' => 'secret-pass'],
            json_decode((string)$request->getBody(), true)
        );
        self::assertSame(10, $this->history[0]['options']['timeout']);
    }

    public function testTheStartAnswersTheEnginesStatusAndBody(): void
    {
        $ok = $this->onboarding([$this->json(200, ['start_url' => 'https://intelligence.smaily.com/onboarding/start#t=a'])])
            ->start([]);
        self::assertSame(['status' => 200, 'body' => ['start_url' => 'https://intelligence.smaily.com/onboarding/start#t=a']], $ok);

        $refused = $this->onboarding([$this->json(409, ['error' => 'store_exists', 'message' => 'Has a store'])])
            ->start([]);
        self::assertSame(409, $refused['status']);
        self::assertSame('store_exists', $refused['body']['error']);
    }

    public function testNoAnswerIsStatusZeroAndOneAttempt(): void
    {
        $answer = $this->onboarding([
            new ConnectException('timed out', new Request('POST', '/')),
            $this->json(200, []),
        ])->start(['smaily_password' => 'secret-pass']);

        self::assertSame(['status' => 0, 'body' => []], $answer);
        self::assertCount(1, $this->history);
    }

    /**
     * @param array<int, Response|\Exception> $responses
     */
    private function onboarding(array $responses): Onboarding
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $config = []): HttpClient => new HttpClient(['handler' => $stack] + $config)
        );

        return new Onboarding($factory);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string)json_encode($body));
    }
}
