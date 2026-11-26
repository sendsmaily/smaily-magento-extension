<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Exception;

/**
 * A privacy request that waits for the engine's limit (CON-432): the limit
 * did not allow it now, or the engine answered 429. Nothing was waited for
 * in the request; the caller tries again after getRetryAfter() seconds.
 */
class EngineThrottledException extends EngineTransportException
{
    public function __construct(
        string $message,
        private readonly int $retryAfter
    ) {
        parent::__construct($message, 429);
    }

    /**
     * Seconds until the next privacy request may go.
     */
    public function getRetryAfter(): int
    {
        return $this->retryAfter;
    }
}
