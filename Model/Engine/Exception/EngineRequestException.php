<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Exception;

/**
 * Non-retryable request failure (4xx other than 429): the request itself is
 * wrong and a retry cannot succeed. Carries the decoded engine error body.
 */
class EngineRequestException extends EngineException
{
    /**
     * @param array<string, mixed> $errorBody
     */
    public function __construct(
        string $message,
        private readonly int $httpStatus,
        private readonly array $errorBody = []
    ) {
        parent::__construct($message, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrorBody(): array
    {
        return $this->errorBody;
    }
}
