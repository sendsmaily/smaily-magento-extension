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
     * The envelope codes that retrying the same request can never change
     * (PRO-1962, the cross-platform canon proposal): 203 "invalid data" —
     * identical data is rejected again. Every other non-success code keeps
     * the retry ladder, as before.
     */
    private const PERMANENT_CODES = [self::CODE_INVALID_DATA];

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
     * Whether Smaily rejected the request itself, so a retry cannot pass.
     */
    public function isPermanent(): bool
    {
        return in_array($this->smailyCode, self::PERMANENT_CODES, true);
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
