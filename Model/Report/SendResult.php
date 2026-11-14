<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

/**
 * What the subscription service answered an installation report (CON-234):
 * the HTTP status (0 when no answer came), the service's error code ('' for
 * none), whether the report may be sent again and the wait it asked for.
 */
class SendResult
{
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $error,
        public readonly bool $retry,
        public readonly int $retryAfter = 0
    ) {
    }

    /** Whether the service took the report in (a 2xx answer). */
    public function isAccepted(): bool
    {
        return $this->httpStatus >= 200 && $this->httpStatus < 300;
    }

    /**
     * One line for the module's log: the status and the error code, never
     * a message, a body or a credential.
     */
    public function summary(): string
    {
        return sprintf('http=%d error=%s', $this->httpStatus, $this->error !== '' ? $this->error : '-');
    }
}
