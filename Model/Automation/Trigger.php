<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Automation;

/**
 * Store-event automation trigger types.
 */
class Trigger
{
    public const WELCOME = 'welcome';
    public const FIRST_ORDER = 'first_order';
    public const ABANDONED_CART = 'abandoned_cart';

    public const ALL = [
        self::WELCOME,
        self::FIRST_ORDER,
        self::ABANDONED_CART,
    ];

    /**
     * Trigger slug => the Smaily contact field recording when this automation
     * last ran for the contact. Cross-platform canon (Erkki 2026-08-04): the
     * Woo and Shopify plugins write the exact same names, so a merchant segment
     * or a "got this letter X days ago" rule transfers between platforms. The
     * names are merchant-visible and permanent — add one, never repurpose one.
     *
     * The value is that run's own clock in MARKER_STAMP_FORMAT, written on
     * every run: last-writer-wins, meaning "this automation ran, most recently
     * at T" — not how the contact entered the list.
     *
     * A trigger writes only its own field. A trigger that did not fire sends no
     * marker at all: absent leaves whatever Smaily already holds intact, while
     * '' would wipe it. `is_abandoned_cart` keeps its template meaning untouched
     * — the abandoned-cart marker rides alongside it in the same payload.
     *
     * @var array<string, string>
     */
    public const MARKER_FIELDS = [
        self::WELCOME => 'welcome_automation_at',
        self::FIRST_ORDER => 'first_order_automation_at',
        self::ABANDONED_CART => 'abandoned_cart_automation_at',
    ];

    /**
     * The shape of every marker stamp on the Smaily contact wire: UTC
     * `Y-m-d H:i:s`, taken with gmdate() at the moment the store event
     * happened.
     *
     * Load-bearing, not cosmetic, and the one place this is stated: it is the
     * shape of the only other date+time on that wire, it is lexicographically
     * ordered, and the merchant's workflow compares two of these values
     * against each other (ABANDONED_CART_PURCHASED_FIELD against this
     * trigger's own marker), so they must sort. It is also what the Woo and
     * Shopify siblings write (Woo decision PRO-1723,
     * `AutomationMarker::purchase_stamp()`); a Z-suffixed form would sort
     * wrong ('T' > ' ') and split the canon the three plugins share.
     */
    public const MARKER_STAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * The EXIT condition of the reminder series: a shopper the store reminded
     * has since bought (PRO-2453, Woo PRO-1723). Not a trigger marker — no
     * automation runs — hence outside MARKER_FIELDS, but it carries
     * MARKER_STAMP_FORMAT so it sorts against them. Merchant-visible and
     * permanent, exactly like them.
     */
    public const ABANDONED_CART_PURCHASED_FIELD = 'abandoned_cart_purchased_at';
}
