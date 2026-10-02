<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

use Magento\Framework\Phrase;

/**
 * Network-level or HTTP-level failure (timeouts, DNS, HTTP >= 400).
 */
class TransportException extends SmailyClientException
{
    public function __construct(
        string|Phrase $message,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
        private readonly ?int $retryAfter = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * HTTP status code of the failed response, 0 for pure network failures.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Seconds Smaily asked the caller to wait (the Retry-After header on a
     * 429), or null when it sent none. Read by the queue's RetryPolicy.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
