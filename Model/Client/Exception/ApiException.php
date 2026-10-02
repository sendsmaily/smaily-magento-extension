<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

use Magento\Framework\Phrase;

/**
 * Smaily API returned an error envelope (HTTP 200 with code other than 101).
 *
 * Known codes: 101 success, 203 invalid data, 206 email not found.
 */
class ApiException extends SmailyClientException
{
    public const CODE_SUCCESS = 101;
    public const CODE_INVALID_DATA = 203;
    public const CODE_EMAIL_NOT_FOUND = 206;

    /**
     * @param array<string, mixed> $response
     */
    public function __construct(
        string|Phrase $message,
        private readonly int $smailyCode,
        private readonly array $response = []
    ) {
        parent::__construct($message, $smailyCode);
    }

    /**
     * Smaily envelope code (e.g. 203 for invalid data).
     */
    public function getSmailyCode(): int
    {
        return $this->smailyCode;
    }

    /**
     * Full decoded response body.
     *
     * @return array<string, mixed>
     */
    public function getResponse(): array
    {
        return $this->response;
    }
}
