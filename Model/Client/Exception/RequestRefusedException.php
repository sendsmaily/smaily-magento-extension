<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

use Magento\Framework\Phrase;

/**
 * Smaily refused this request: an HTTP 4xx other than 429. Retrying the same
 * request cannot change the answer (revoked credentials, a deleted workflow,
 * a rejected payload), so the queue stops on the first one (PRO-1800). Typed
 * where it is thrown, as the engine client's EngineRequestException is; a
 * 429, a 5xx and a network failure are a TransportException (PRO-1961).
 */
class RequestRefusedException extends SmailyClientException
{
    public function __construct(
        string|Phrase $message,
        private readonly int $httpStatus,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
    }

    /**
     * HTTP status code of the refusal.
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
