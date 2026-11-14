<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Report;

/**
 * One installation report (CON-234): the body of `POST /v1/reports` and the
 * main account's credentials that authenticate it.
 */
class Report
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public readonly array $body,
        public readonly Credentials $credentials
    ) {
    }
}
