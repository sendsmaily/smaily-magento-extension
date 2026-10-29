<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

/**
 * How an erasure and an export (CON-242), and the abandoned-cart reminder's
 * newsletter-status check (CON-243), find a shopper's address in a table
 * column: in any letter case, and an accent makes it another address.
 *
 * The collations Magento's tables use (utf8mb4_general_ci, utf8_general_ci,
 * utf8mb4_0900_ai_ci) ignore accents as well as case, so `LOWER(email) = ?`
 * found `jäne@…`'s rows for `jane@…`. Here the database lowercases both
 * sides — a capital with an accent too, as PayloadAnonymizer's match does —
 * and then compares the bytes. The same rule as the WooCommerce plugin's.
 */
class AddressMatch
{
    /**
     * `$column` holds the address: one `?`, bound to the address.
     *
     * A function on the column keeps the database from using an index on
     * it; a caller whose column has one adds a plain `$column = ?` in front.
     *
     * @param string $column a column name from code, never from a request
     */
    public static function condition(string $column): string
    {
        return 'CAST(LOWER(' . $column . ') AS BINARY) = CAST(LOWER(?) AS BINARY)';
    }
}
