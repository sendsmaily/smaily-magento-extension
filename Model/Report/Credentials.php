<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

/**
 * A Smaily account's API credentials, as the installation report's
 * Authorization header carries them (CON-234). They never go in the body,
 * a log line or an exception message.
 */
class Credentials
{
    public function __construct(
        public readonly string $subdomain,
        public readonly string $username,
        public readonly string $password
    ) {
    }

    /** The Authorization header value: HTTP Basic with the API user and password. */
    public function authorization(): string
    {
        return 'Basic ' . base64_encode($this->username . ':' . $this->password);
    }
}
