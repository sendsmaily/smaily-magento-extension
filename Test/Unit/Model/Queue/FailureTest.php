<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Unit\Model\Queue;

use PHPUnit\Framework\TestCase;
use Smaily\Connect\Model\Client\Exception\ApiException;
use Smaily\Connect\Model\Client\Exception\AuthenticationException;
use Smaily\Connect\Model\Client\Exception\PlanBlockedException;
use Smaily\Connect\Model\Client\Exception\RequestRefusedException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;
use Smaily\Connect\Model\Engine\Exception\EngineTransportException;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Test\Unit\Support\StoreLocale;

/**
 * The one classification of a failed marketing-queue send (PRO-1800,
 * PRO-1961): the clients type a refusal when they throw it, and a refusal
 * that retrying cannot change stops on the first attempt.
 */
class FailureTest extends TestCase
{
    /**
     * @return array<string, array{0: \Throwable, 1: string}>
     */
    public static function permanentRefusals(): array
    {
        return [
            'revoked credentials' => [new AuthenticationException('Rejected', 401), 'permanent_http_401: Rejected'],
            'forbidden' => [new AuthenticationException('Rejected', 403), 'permanent_http_403: Rejected'],
            'plan blocked' => [new PlanBlockedException('No API access', 403), 'permanent_http_403: No API access'],
            'workflow gone' => [new RequestRefusedException('Not found', 404), 'permanent_http_404: Not found'],
            'rejected payload' => [
                new RequestRefusedException('Unprocessable', 422),
                'permanent_http_422: Unprocessable',
            ],
            // PRO-1961: an engine 4xx on an identity merge, the same class.
            'engine refusal' => [
                new EngineRequestException('Engine request failed with HTTP 422: unknown session', 422),
                'permanent_http_422: Engine request failed with HTTP 422: unknown session',
            ],
        ];
    }

    /**
     * @dataProvider permanentRefusals
     */
    public function testARefusalRetryingCannotChangeIsPermanent(\Throwable $exception, string $reason): void
    {
        $failure = Failure::of($exception);

        self::assertTrue($failure->permanent);
        self::assertSame($reason, $failure->reason);
        self::assertNull($failure->retryAfter);
    }

    /**
     * @return array<string, array{0: \Throwable, 1: string, 2: int|null}>
     */
    public static function temporaryFailures(): array
    {
        return [
            'slow down' => [new TransportException('Slow down', 429, null, 120), 'Slow down', 120],
            'slow down without a header' => [new TransportException('Slow down', 429), 'Slow down', null],
            'bad gateway' => [new TransportException('Bad gateway', 502), 'Bad gateway', null],
            'no status' => [new TransportException('Connection timed out'), 'Connection timed out', null],
            // HTTP 200 with a non-101 body carries no HTTP status.
            'error envelope' => [
                new ApiException('Invalid data', ApiException::CODE_INVALID_DATA),
                'Invalid data',
                null,
            ],
            'missing credentials' => [
                new SmailyClientException('Credentials are not configured'),
                'Credentials are not configured',
                null,
            ],
            'engine outage' => [
                new EngineTransportException('Engine request failed with HTTP 503 after retries', 503),
                'Engine request failed with HTTP 503 after retries',
                null,
            ],
        ];
    }

    /**
     * @dataProvider temporaryFailures
     */
    public function testAnythingElseKeepsTheLadder(\Throwable $exception, string $reason, ?int $retryAfter): void
    {
        $failure = Failure::of($exception);

        self::assertFalse($failure->permanent);
        self::assertSame($reason, $failure->reason);
        self::assertSame($retryAfter, $failure->retryAfter);
    }

    /**
     * PRO-1961: a handler's own verdict that the row can never be sent
     * (a malformed payload, a row no handler takes).
     */
    public function testAHandlerVerdictIsPermanentWithItsOwnReason(): void
    {
        $failure = Failure::permanent('Malformed contact payload');

        self::assertTrue($failure->permanent);
        self::assertSame('Malformed contact payload', $failure->reason);
        self::assertNull($failure->retryAfter);
    }

    /**
     * PRO-3628: the row keeps the English source text even when the cron
     * run translated the message for an Estonian store. The admin
     * translates it when it shows the row.
     */
    public function testTheRowKeepsTheEnglishSourceTextOfATranslatedError(): void
    {
        StoreLocale::use('et_EE');

        self::assertSame(
            'permanent_http_404: Smaily API request failed with HTTP 404',
            Failure::of(new RequestRefusedException(__('Smaily API request failed with HTTP %1', 404), 404))->reason
        );
        self::assertSame(
            'Smaily API request failed with HTTP 503',
            Failure::of(new TransportException(__('Smaily API request failed with HTTP %1', 503), 503))->reason
        );
    }

    protected function tearDown(): void
    {
        StoreLocale::reset();
    }
}
