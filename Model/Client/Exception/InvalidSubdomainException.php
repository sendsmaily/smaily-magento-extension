<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Client\Exception;

/**
 * The Smaily subdomain is not one plain label (SmailyUrl::isPlainSubdomain()),
 * so no request is made with it.
 */
class InvalidSubdomainException extends SmailyClientException
{
    public function __construct()
    {
        parent::__construct(
            __('The subdomain must be a plain Smaily subdomain such as "demo": letters, digits and hyphens only.')
        );
    }
}
