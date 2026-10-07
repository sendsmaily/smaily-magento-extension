<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support;

use Smaily\Connect\Model\Log\QueueRowLoader;

/**
 * The real row loader, recording how many ids each loadFailed() call asks
 * about — the mass retry's batch sizes.
 */
class RecordingRowLoader extends QueueRowLoader
{
    /**
     * @var array<int, array{0: string, 1: int}> source and id count per call
     */
    public array $batches = [];

    /**
     * @inheritDoc
     */
    public function loadFailed(string $source, array $ids): array
    {
        $this->batches[] = [$source, count($ids)];

        return parent::loadFailed($source, $ids);
    }
}
