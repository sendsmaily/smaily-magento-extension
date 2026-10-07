<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Test\Integration\Support\Fake;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Fixed-UTC timezone provider. Only date() is exercised by the code under
 * test (Stdlib DateTime::gmtTimestamp delegates timestamp normalization to
 * it); the locale-formatting methods are unsupported by design.
 */
class FakeTimezone implements TimezoneInterface
{
    /**
     * @inheritDoc
     */
    public function getDefaultTimezonePath()
    {
        return 'general/locale/timezone';
    }

    /**
     * @inheritDoc
     */
    public function getDefaultTimezone()
    {
        return 'UTC';
    }

    /**
     * @inheritDoc
     */
    public function getDateFormat($type = \IntlDateFormatter::SHORT)
    {
        throw new \BadMethodCallException('Locale date formats are not available in the integration test harness');
    }

    /**
     * @inheritDoc
     */
    public function getDateFormatWithLongYear()
    {
        throw new \BadMethodCallException('Locale date formats are not available in the integration test harness');
    }

    /**
     * @inheritDoc
     */
    public function getTimeFormat($type = null)
    {
        throw new \BadMethodCallException('Locale time formats are not available in the integration test harness');
    }

    /**
     * @inheritDoc
     */
    public function getDateTimeFormat($type)
    {
        throw new \BadMethodCallException('Locale datetime formats are not available in the integration test harness');
    }

    /**
     * @inheritDoc
     */
    public function date($date = null, $locale = null, $useTimezone = true, $includeTime = true)
    {
        $utc = new \DateTimeZone('UTC');
        if ($date === null) {
            return new \DateTime('now', $utc);
        }
        if ($date instanceof \DateTimeInterface) {
            return (new \DateTime('@' . $date->getTimestamp()))->setTimezone($utc);
        }
        if (is_numeric($date)) {
            return (new \DateTime('@' . (int)$date))->setTimezone($utc);
        }

        return new \DateTime((string)$date, $utc);
    }

    /**
     * @param mixed $scope
     * @param string|int|\DateTimeInterface|null $date
     * @param bool $includeTime
     * @return \DateTime
     */
    public function scopeDate($scope = null, $date = null, $includeTime = false)
    {
        return $this->date($date);
    }

    /**
     * @inheritDoc
     */
    public function scopeTimeStamp($scope = null)
    {
        return time();
    }

    /**
     * @inheritDoc
     */
    public function formatDate($date = null, $format = \IntlDateFormatter::SHORT, $showTime = false)
    {
        throw new \BadMethodCallException('Locale date formatting is not available in the integration test harness');
    }

    /**
     * @inheritDoc
     */
    public function getConfigTimezone($scopeType = null, $scopeCode = null)
    {
        return 'UTC';
    }

    /**
     * @inheritDoc
     */
    public function isScopeDateInInterval($scope, $dateFrom = null, $dateTo = null)
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function formatDateTime(
        $date,
        $dateType = \IntlDateFormatter::SHORT,
        $timeType = \IntlDateFormatter::SHORT,
        $locale = null,
        $timezone = null,
        $pattern = null
    ) {
        throw new \BadMethodCallException('Locale datetime formatting is not available in the integration harness');
    }

    /**
     * @inheritDoc
     */
    public function convertConfigTimeToUtc($date, $format = 'Y-m-d H:i:s')
    {
        return $this->date($date)->format($format);
    }
}
