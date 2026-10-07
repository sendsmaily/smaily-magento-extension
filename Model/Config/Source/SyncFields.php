<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * Optional contact fields. The values ARE the Smaily wire keys, so they follow
 * Smaily's cross-platform field standard as shipped by the WooCommerce plugin
 * (`user_phone`, `user_gender`) — the same contact reaching Smaily from two
 * stores must land in one field, not two.
 */
class SyncFields implements OptionSourceInterface
{
    public const FIELD_FIRST_NAME = 'first_name';
    public const FIELD_LAST_NAME = 'last_name';
    public const FIELD_PREFIX = 'prefix';
    public const FIELD_PHONE = 'user_phone';
    public const FIELD_GENDER = 'user_gender';
    public const FIELD_BIRTHDAY = 'birthday';
    public const FIELD_CUSTOMER_ID = 'customer_id';
    public const FIELD_CUSTOMER_GROUP = 'customer_group';
    public const FIELD_SUBSCRIPTION_TYPE = 'subscription_type';

    public const SUPPORTED_FIELDS = [
        self::FIELD_FIRST_NAME,
        self::FIELD_LAST_NAME,
        self::FIELD_PREFIX,
        self::FIELD_PHONE,
        self::FIELD_GENDER,
        self::FIELD_BIRTHDAY,
        self::FIELD_CUSTOMER_ID,
        self::FIELD_CUSTOMER_GROUP,
        self::FIELD_SUBSCRIPTION_TYPE,
    ];

    /**
     * @inheritDoc
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::FIELD_FIRST_NAME, 'label' => __('First Name')],
            ['value' => self::FIELD_LAST_NAME, 'label' => __('Last Name')],
            ['value' => self::FIELD_PREFIX, 'label' => __('Name Prefix')],
            ['value' => self::FIELD_PHONE, 'label' => __('Phone')],
            ['value' => self::FIELD_GENDER, 'label' => __('Gender')],
            ['value' => self::FIELD_BIRTHDAY, 'label' => __('Date of Birth')],
            ['value' => self::FIELD_CUSTOMER_ID, 'label' => __('Customer ID')],
            ['value' => self::FIELD_CUSTOMER_GROUP, 'label' => __('Customer Group')],
            ['value' => self::FIELD_SUBSCRIPTION_TYPE, 'label' => __('Subscription Type')],
        ];
    }
}
