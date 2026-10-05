<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Smaily\Connect\Api\Queue\EventHandlerInterface;
use Smaily\Connect\Model\Queue\Event;
use Smaily\Connect\Model\Queue\Failure;
use Smaily\Connect\Model\Queue\Pending;
use Smaily\Connect\Model\Queue\Skipped;

/**
 * Scriptable queue event handler standing in for the real handlers (whose
 * HTTP transport is out of scope here): records the batches it receives
 * and answers with a caller-provided responder.
 */
class RecordingHandler implements EventHandlerInterface
{
    /**
     * @var callable(Event[]): array<int, true|string|\Throwable|Failure|Skipped|Pending>
     */
    private $responder;

    /**
     * @var array<int, Event[]>
     */
    private $batches = [];

    /**
     * @param callable(Event[]): array<int, true|string|\Throwable|Failure|Skipped|Pending> $responder
     */
    public function __construct(callable $responder)
    {
        $this->responder = $responder;
    }

    /**
     * @inheritDoc
     */
    public function handle(array $events): array
    {
        $this->batches[] = $events;

        return ($this->responder)($events);
    }

    /**
     * Batches received so far.
     *
     * @return array<int, Event[]>
     */
    public function getBatches(): array
    {
        return $this->batches;
    }
}
