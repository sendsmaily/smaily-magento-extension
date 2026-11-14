<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Report;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Smaily\Connect\Model\Client\HttpClientFactory;
use Smaily\Connect\Model\ModuleInfo;
use Smaily\Connect\Model\Report\Credentials;
use Smaily\Connect\Model\Report\ReportSender;

/**
 * CON-234: one `POST https://subscription.smaily.com/v1/reports` — Basic
 * auth with the main account in the header only, JSON, a 3 s connect and
 * 5 s total timeout, the certificate verified, never a redirect — and the
 * answer read as contract installation-report-v1 "What the plugin does with
 * each answer" says: only a 429, a 5xx or no answer may be tried again.
 */
class ReportSenderTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface, options: array<string, mixed>}> */
    private array $history = [];

    public function testTheReportIsPostedAsTheContractSays(): void
    {
        $result = $this->sender([new Response(200, [], '{"installation_id":"3f8a1c52-7b9e-4d1a-9c3e-2a6f5b8d0e17"}')])
            ->send(['product' => 'connect_magento', 'installation_url' => 'https://example-store.test'], $this->credentials());

        self::assertTrue($result->isAccepted());
        self::assertFalse($result->retry);
        $request = $this->history[0]['request'];
        $options = $this->history[0]['options'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://subscription.smaily.com/v1/reports', (string)$request->getUri());
        self::assertSame('Basic ' . base64_encode('api-user:not-a-real-password'), $request->getHeaderLine('Authorization'));
        self::assertSame('application/json; charset=utf-8', $request->getHeaderLine('Content-Type'));
        self::assertSame(ModuleInfo::USER_AGENT, $request->getHeaderLine('User-Agent'));
        self::assertSame('{"product":"connect_magento","installation_url":"https://example-store.test"}', (string)$request->getBody());
        self::assertSame(3, $options['connect_timeout']);
        self::assertSame(5, $options['timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertTrue($options['verify']);
        self::assertSame(CURL_SSLVERSION_TLSv1_2, $options['curl'][CURLOPT_SSLVERSION]);
    }

    /**
     * @dataProvider answers
     */
    public function testEachAnswerIsReadAsTheContractSays(
        Response|callable $answer,
        int $status,
        string $error,
        bool $retry,
        int $retryAfter
    ): void {
        $result = $this->sender([$answer])->send(['product' => 'connect_magento'], $this->credentials());

        self::assertSame([$status, $error, $retry, $retryAfter], [
            $result->httpStatus,
            $result->error,
            $result->retry,
            $result->retryAfter,
        ]);
        self::assertCount(1, $this->history, 'One request: nothing is followed or repeated here.');
        self::assertStringNotContainsString('not-a-real-password', $result->summary());
    }

    /**
     * @return array<string, array{Response|callable, int, string, bool, int}>
     */
    public static function answers(): array
    {
        $error = static fn (int $status, string $code, array $headers = []): Response
            => new Response($status, $headers + ['Content-Type' => 'application/json'], (string)json_encode([
                'error' => $code,
                'message' => 'A sentence for a log line.',
            ]));

        return [
            'accepted' => [new Response(200, [], '{"installation_id":"x"}'), 200, '', false, 0],
            'refused body' => [$error(400, 'validation_failed'), 400, 'validation_failed', false, 0],
            'refused credentials' => [$error(401, 'invalid_smaily_credentials'), 401, 'invalid_smaily_credentials', false, 0],
            'too large' => [$error(413, 'body_too_large'), 413, 'body_too_large', false, 0],
            'a redirect is not followed' => [
                new Response(308, ['Location' => 'https://elsewhere.test/v1/reports']),
                308,
                'redirect',
                false,
                0,
            ],
            'rate limited' => [$error(429, 'rate_limited', ['Retry-After' => '120']), 429, 'rate_limited', true, 120],
            'Smaily unreachable' => [
                $error(503, 'smaily_unreachable', ['Retry-After' => '30']),
                503,
                'smaily_unreachable',
                true,
                30,
            ],
            'a proxy error without a body' => [new Response(502, [], '<html>Bad gateway</html>'), 502, '', true, 0],
            'a Retry-After as a date is ignored' => [
                $error(503, 'unavailable', ['Retry-After' => 'Wed, 21 Oct 2026 07:28:00 GMT']),
                503,
                'unavailable',
                true,
                0,
            ],
            'an error code outside the rule is not kept' => [$error(400, 'Not A Code'), 400, '', false, 0],
            'no answer' => [
                static fn (RequestInterface $request) => new ConnectException('Connection timed out', $request),
                0,
                'network_error',
                true,
                0,
            ],
        ];
    }

    public function testABodyOverTheLimitIsNotSent(): void
    {
        $result = $this->sender([])->send(['padding' => str_repeat('x', ReportSender::MAX_BODY_BYTES)], $this->credentials());

        self::assertSame([0, 'body_too_large', false], [$result->httpStatus, $result->error, $result->retry]);
        self::assertSame([], $this->history);
    }

    /**
     * @param array<int, Response|callable> $answers
     */
    private function sender(array $answers): ReportSender
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $factory = $this->createMock(HttpClientFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (array $config = []): HttpClient => new HttpClient($config + ['handler' => $stack])
        );

        return new ReportSender($factory);
    }

    private function credentials(): Credentials
    {
        return new Credentials('example', 'api-user', 'not-a-real-password');
    }
}
