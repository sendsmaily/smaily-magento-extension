<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Api;

/**
 * Puts the email a guest typed at checkout on the guest's cart before the
 * shopper submits it, so the abandoned-cart scan can reach a guest who
 * leaves at the shipping step (REST: POST
 * /V1/smaily-connect/guest-carts/:cartId/email, anonymous).
 */
interface GuestCartEmailInterface
{
    /**
     * Set the email on an active guest cart that has items.
     *
     * Answers false, without saying why, for every request that changes
     * nothing: an unknown, customer, inactive or empty cart, an invalid
     * email, a caller over the rate limit or a cart whose email changed too
     * often.
     *
     * @param string $cartId The guest cart's masked id.
     * @param string $email
     * @return bool
     */
    public function set(string $cartId, string $email): bool;
}
