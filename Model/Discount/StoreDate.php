<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Discount;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * A calendar day in a store's own timezone (Stores > Configuration >
 * General > Locale Options > Timezone), as `Y-m-d` — the day a cart price
 * rule's From and To dates and a discount code's last valid day are read in
 * (CON-198).
 */
class StoreDate
{
    public function __construct(
        private readonly DateTime $dateTime,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Today in the store's timezone, or the day $plusDays after it. A null
     * store reads the default scope's timezone.
     */
    public function today(?int $storeId, int $plusDays = 0): string
    {
        $timezone = (string)$this->timezone->getConfigTimezone(
            ScopeInterface::SCOPE_STORE,
            $storeId === null ? null : (string)$storeId
        );
        $day = (new \DateTimeImmutable('@' . $this->dateTime->gmtTimestamp()))
            ->setTimezone(new \DateTimeZone($timezone !== '' ? $timezone : 'UTC'));
        if ($plusDays !== 0) {
            $day = $day->modify(sprintf('%+d days', $plusDays));
        }

        return $day->format('Y-m-d');
    }
}
