<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Queue;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * A stored queue payload as an array. Both queues, the Log's "Send again"
 * and the Details drawer read the same stored JSON, so they read it the
 * same way: an object becomes its array, anything else becomes nothing.
 */
class PayloadDecoder
{
    public function __construct(
        private readonly Json $serializer
    ) {
    }

    /**
     * @return array<int|string, mixed>
     */
    public function decode(string $payload): array
    {
        if ($payload === '') {
            return [];
        }

        $decoded = $this->serializer->unserialize($payload);

        return is_array($decoded) ? $decoded : [];
    }
}
