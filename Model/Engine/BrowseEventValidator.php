<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

/**
 * Sanitizes browser-supplied browse events into WireBrowseEvent shape
 * (contract §6). Unknown keys are dropped; the server stamps source and
 * event_ts so clients cannot spoof them arbitrarily.
 */
class BrowseEventValidator
{
    public const SOURCE = 'plugin_magento';

    private const EVENT_TYPES = [
        'product_view',
        'category_view',
        'search',
        'cart_add',
        'cart_remove',
        'wishlist_add',
        'wishlist_remove',
        'checkout_start',
        'checkout_complete',
    ];

    private const UUID_PATTERN =
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null null when the event is not salvageable
     */
    public function sanitize(array $event): ?array
    {
        $eventId = (string)($event['event_id'] ?? '');
        $sessionId = trim((string)($event['session_id'] ?? ''));
        $eventType = (string)($event['event_type'] ?? '');

        if (preg_match(self::UUID_PATTERN, $eventId) !== 1
            || $sessionId === '' || strlen($sessionId) > 64
            || !in_array($eventType, self::EVENT_TYPES, true)
        ) {
            return null;
        }

        $clean = [
            'event_id' => strtolower($eventId),
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'event_ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'source' => self::SOURCE,
        ];

        // customer_email and external_id are deliberately NOT accepted from
        // the anonymous beacon — the beacon asserts no identity of its own
        // (tracker.js never sends either). Identity reaches the engine only
        // from server-side state: the engine-issued visitor token and the
        // identity-merge flow.
        //
        // smaily_rec_id / smaily_ctx are not accepted either: the engine
        // stopped persisting and consulting them in contract v1.7.0
        // (accept-and-ignore), and a malformed smaily_rec_id still fails that
        // event's UUID validation — forwarding them can only cost events.
        // Rec-link attribution runs on the order-level cookie->order path
        // (§5, Engine\AttributionManager), which is untouched.
        foreach (['sku', 'category_path', 'search_query', 'smaily_visitor_token'] as $field) {
            $value = trim((string)($event[$field] ?? ''));
            if ($value !== '' && strlen($value) <= 255) {
                $clean[$field] = $value;
            }
        }

        if (isset($event['dwell_seconds']) && is_numeric($event['dwell_seconds'])) {
            $clean['dwell_seconds'] = max(0, (int)$event['dwell_seconds']);
        }

        return $clean;
    }
}
