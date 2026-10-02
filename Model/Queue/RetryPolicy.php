<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\Exception\TransportException;

/**
 * The one place that decides what happens to a Smaily queue row after a
 * failed send — the cross-platform classification the sibling plugins ship
 * (verified against Woo's `RetryPolicy`, its PRO-1685):
 *
 *  - PERMANENT (4xx except 429): stop on the FIRST refusal. The row is parked
 *    as failed with a `permanent_http_<code>` reason, so a refusal retrying
 *    cannot change (revoked credentials, a deleted workflow, a rejected
 *    payload) reaches the merchant's failed count now instead of six hours
 *    and four pointless re-POSTs later.
 *  - TEMPORARY (429, 5xx, transport error with no status): retry on the
 *    existing ladder (1m, 5m, 15m, 1h, 6h, then failed), spaced by Smaily's
 *    own Retry-After when a 429 named one.
 *
 * Biased toward retrying, like the sibling: anything without a recognisable
 * permanent status is temporary, because mis-classifying a recoverable
 * failure drops genuine work. A Smaily error envelope on HTTP 200
 * (ApiException) carries no HTTP status and so stays temporary, exactly as
 * before. Either way, the Log's Retry action is the recovery path for any
 * failed row.
 */
class RetryPolicy
{
    private ?SmailyClientException $lastException = null;

    /**
     * @var array{reason: string, retryAfter: ?int, terminal: bool}|null
     */
    private ?array $lastVerdict = null;

    public function __construct(
        private readonly EventQueue $eventQueue
    ) {
    }

    /**
     * Advance one row after a failed send: park it for good, or reschedule it.
     */
    public function apply(Event $event, SmailyClientException $exception): void
    {
        $verdict = $this->classify($exception);

        $this->eventQueue->markFailed(
            $event,
            $verdict['reason'],
            retryAfter: $verdict['retryAfter'],
            terminal: $verdict['terminal']
        );
    }

    /**
     * Classify one failure. A batch refusal hands the SAME exception object to
     * every row of the batch (up to 200), so the verdict — including formatting
     * and truncating a message that can be huge — is computed once and reused.
     *
     * @return array{reason: string, retryAfter: ?int, terminal: bool}
     */
    private function classify(SmailyClientException $exception): array
    {
        if ($this->lastException === $exception && $this->lastVerdict !== null) {
            return $this->lastVerdict;
        }

        // 4xx bar 429 can never succeed on a retry — the request itself is the
        // problem. Anything else (429, 5xx, a transport error or a Smaily error
        // envelope, neither of which carries an HTTP status) stays retryable.
        if ($exception instanceof TransportException && $this->isPermanent($exception->getHttpStatus())) {
            $verdict = [
                'reason' => sprintf(
                    'permanent_http_%d: %s',
                    $exception->getHttpStatus(),
                    $exception->getSourceMessage()
                ),
                'retryAfter' => null,
                'terminal' => true,
            ];
        } else {
            $verdict = [
                'reason' => $exception->getSourceMessage(),
                'retryAfter' => $exception instanceof TransportException ? $exception->getRetryAfter() : null,
                'terminal' => false,
            ];
        }

        $verdict['reason'] = mb_substr($verdict['reason'], 0, EventQueue::MAX_ERROR_LENGTH);
        $this->lastException = $exception;
        $this->lastVerdict = $verdict;

        return $verdict;
    }

    private function isPermanent(int $status): bool
    {
        return $status >= 400 && $status < 500 && $status !== 429;
    }
}
