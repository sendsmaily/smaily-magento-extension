<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Exception;

/**
 * Retryable transport failure (network error, 429 after retries, 5xx).
 */
class EngineTransportException extends EngineException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
