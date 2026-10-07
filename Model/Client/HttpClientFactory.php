<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client;

use GuzzleHttp\Client as HttpClient;

/**
 * Creates configured Guzzle HTTP clients (test seam).
 */
class HttpClientFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config = []): HttpClient
    {
        return new HttpClient($config);
    }
}
