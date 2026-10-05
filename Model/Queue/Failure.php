<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Smaily\Connect\Model\Client\Exception\RequestRefusedException;
use Smaily\Connect\Model\Client\Exception\SmailyClientException;
use Smaily\Connect\Model\Client\Exception\TransportException;
use Smaily\Connect\Model\Engine\Exception\EngineRequestException;

/**
 * What happens to a marketing-queue row whose send failed: the reason the
 * Log shows, and whether the row stops for good or takes the retry ladder
 * (PRO-1800, PRO-1961). The cross-platform classification of the sibling
 * plugins (Woo's `RetryPolicy`, its PRO-1685):
 *
 *  - PERMANENT: a refusal retrying cannot change — a Smaily or engine 4xx
 *    other than 429 (revoked credentials, a deleted workflow, a rejected
 *    payload), stored as `permanent_http_<code>: <message>`, or a handler's
 *    own verdict (a malformed payload, a row no handler takes). The row is
 *    parked as failed on the first attempt.
 *  - TEMPORARY: anything else — a 429 (spaced by Smaily's Retry-After when
 *    it sent one), a 5xx, a network failure, a Smaily error envelope on
 *    HTTP 200 — keeps the ladder (1m, 5m, 15m, 1h, 6h, then failed).
 *
 * The clients type a failure when they throw it (RequestRefusedException,
 * EngineRequestException), so this class only reads the type. Biased toward
 * retrying, like the sibling: mis-classifying a recoverable failure drops
 * genuine work. Either way, the Log's Retry action recovers any failed row.
 */
class Failure
{
    private function __construct(
        public readonly string $reason,
        public readonly bool $permanent,
        public readonly ?int $retryAfter
    ) {
    }

    /**
     * A handler's own verdict that the row can never be sent.
     */
    public static function permanent(string $reason): self
    {
        return new self($reason, true, null);
    }

    /**
     * The verdict on a failure a client threw. The row keeps the English
     * source text of a Smaily message; the Log translates it (PRO-3628).
     */
    public static function of(\Throwable $failure): self
    {
        $message = $failure instanceof SmailyClientException ? $failure->getSourceMessage() : $failure->getMessage();
        if ($failure instanceof RequestRefusedException || $failure instanceof EngineRequestException) {
            return self::permanent(sprintf('permanent_http_%d: %s', $failure->getHttpStatus(), $message));
        }

        return new self($message, false, $failure instanceof TransportException ? $failure->getRetryAfter() : null);
    }
}
