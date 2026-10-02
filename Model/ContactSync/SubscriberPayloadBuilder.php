<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\ContactSync;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Config\Source\SyncFields;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * Single source of truth for the Magento -> Smaily contact payload shape
 * (POST /api/contact.php).
 *
 * Always sent: email, store, store_group, store_website (human-readable
 * names, legacy-compatible), language. is_unsubscribed is included only when
 * explicitly known ($isUnsubscribed null = omitted: Smaily keeps an existing
 * contact's status and creates a new contact as subscribed — the soft opt-in
 * of the all-customers mode). Optional fields follow the merchant's
 * sync_fields selection; empty source values are OMITTED because Smaily
 * treats absent (keep) and empty (wipe) differently.
 */
class SubscriberPayloadBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly LanguageResolver $languageResolver,
        private readonly GroupRepositoryInterface $groupRepository
    ) {
    }

    /**
     * @return array<string, string|int>
     */
    public function build(
        string $email,
        int $storeId,
        ?bool $isUnsubscribed,
        ?CustomerInterface $customer = null
    ): array {
        $payload = ['email' => strtolower(trim($email))];
        if ($isUnsubscribed !== null) {
            $payload['is_unsubscribed'] = $isUnsubscribed ? 1 : 0;
        }

        $websiteId = null;
        try {
            $store = $this->storeManager->getStore($storeId);
            if ($store instanceof \Magento\Store\Model\Store) {
                $payload['store'] = (string)$store->getName();
                // getGroup(), not the magic getStoreGroup() (which reads the
                // unset 'store_group' data key and silently yields null).
                $group = $store->getGroup();
                $payload['store_group'] = $group ? (string)$group->getName() : '';
                $payload['store_website'] = (string)$store->getWebsite()->getName();
            }
            $websiteId = (int)$store->getWebsiteId();
        } catch (LocalizedException) {
            // Website scope falls back to the default field selection.
        }

        $language = $this->languageResolver->forStore($storeId);
        if ($language !== '') {
            $payload['language'] = $language;
        }

        foreach ($this->enabledFields($websiteId) as $field) {
            $value = $this->resolveField($field, $customer);
            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        return $payload;
    }

    /**
     * @return string[]
     */
    private function enabledFields(?int $websiteId): array
    {
        return array_values(array_intersect(SyncFields::SUPPORTED_FIELDS, $this->config->getSyncFields($websiteId)));
    }

    private function resolveField(string $field, ?CustomerInterface $customer): ?string
    {
        if ($field === SyncFields::FIELD_SUBSCRIPTION_TYPE) {
            return 'Subscriber';
        }
        if ($customer === null) {
            return $field === SyncFields::FIELD_CUSTOMER_GROUP ? 'Guest' : null;
        }

        switch ($field) {
            case SyncFields::FIELD_FIRST_NAME:
                return $this->trimOrNull((string)$customer->getFirstname());
            case SyncFields::FIELD_LAST_NAME:
                return $this->trimOrNull((string)$customer->getLastname());
            case SyncFields::FIELD_PREFIX:
                return $this->trimOrNull((string)$customer->getPrefix());
            case SyncFields::FIELD_PHONE:
                return $this->billingPhone($customer);
            case SyncFields::FIELD_GENDER:
                // Magento gender attribute: 1 = Male, 2 = Female (legacy
                // data-handler convention; other options are omitted).
                return match ((int)$customer->getGender()) {
                    1 => 'Male',
                    2 => 'Female',
                    default => null,
                };
            case SyncFields::FIELD_BIRTHDAY:
                $dob = (string)$customer->getDob();
                if ($dob === '') {
                    return null;
                }
                $timestamp = strtotime($dob);

                return $timestamp === false ? null : gmdate('Y-m-d', $timestamp);
            case SyncFields::FIELD_CUSTOMER_ID:
                return $customer->getId() ? (string)$customer->getId() : null;
            case SyncFields::FIELD_CUSTOMER_GROUP:
                return $this->groupCode((int)$customer->getGroupId());
            default:
                return null;
        }
    }

    /**
     * The customer's default billing telephone — the same source the engine's
     * customer payload uses, so both wires quote one phone number.
     */
    private function billingPhone(CustomerInterface $customer): ?string
    {
        foreach ((array)$customer->getAddresses() as $address) {
            if ($address->isDefaultBilling()) {
                return $this->trimOrNull((string)$address->getTelephone());
            }
        }

        return null;
    }

    private function groupCode(int $groupId): ?string
    {
        try {
            $code = (string)$this->groupRepository->getById($groupId)->getCode();

            return $code !== '' ? $code : null;
        } catch (LocalizedException) {
            return null;
        }
    }

    private function trimOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
