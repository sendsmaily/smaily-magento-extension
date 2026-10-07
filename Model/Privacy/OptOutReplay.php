<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Privacy;

use Smaily\Connect\Model\Engine\Client;

/**
 * A customer or an order the engine confirmed has its shopper's stored
 * profiling opt-out sent again (PRO-3760): the engine kept no opt-out made
 * before it knew the shopper, and customer and order data carry no consent.
 * The ingest flusher (Cron\FlushIngestQueue) hands over each batch's
 * confirmed items; ProfilingConsent::resendOptOuts() decides what goes.
 */
class OptOutReplay
{
    /**
     * The item field that names the shopper, for the domains whose
     * confirmation sends a stored opt-out again.
     */
    private const SHOPPER_EMAIL_FIELDS = [
        Client::DOMAIN_CUSTOMERS => 'email',
        Client::DOMAIN_ORDERS => 'customer_email',
    ];

    public function __construct(
        private readonly ProfilingConsent $profilingConsent
    ) {
    }

    /**
     * @param string $domain the ingest domain of the batch
     * @param array<int, array<string, mixed>> $confirmedItems the items the engine took, as sent
     */
    public function afterConfirmed(string $domain, array $confirmedItems): void
    {
        $emailField = self::SHOPPER_EMAIL_FIELDS[$domain] ?? null;
        if ($emailField === null || !$confirmedItems) {
            return;
        }

        $this->profilingConsent->resendOptOuts(array_map(
            static fn (array $item): string => (string)($item[$emailField] ?? ''),
            array_values($confirmedItems)
        ));
    }
}
