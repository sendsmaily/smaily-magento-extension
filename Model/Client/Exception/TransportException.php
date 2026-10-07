<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

use Magento\Framework\Phrase;

/**
 * A failure that may pass later: a network failure (timeouts, DNS), a
 * malformed answer, an HTTP 429 or 5xx. A 4xx other than 429 is a
 * RequestRefusedException instead (PRO-1961).
 */
class TransportException extends SmailyClientException
{
    use HttpStatusCode;

    /**
     * @param int $httpStatus HTTP status code of the failed response, 0 for pure network failures
     */
    public function __construct(
        string|Phrase $message,
        int $httpStatus = 0,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * Seconds Smaily asked the caller to wait (the Retry-After header on a
     * 429), or null when it sent none. Read by the queue (Model\Queue\Failure).
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
