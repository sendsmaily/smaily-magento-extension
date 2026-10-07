<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Customer\Api\Data\CustomerInterface;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * Customer -> WireCustomer (contract §4). Identity is the lowercased email;
 * NO consent fields ever — the engine is a separate lawful surface from
 * Smaily marketing consent.
 */
class CustomerPayloadBuilder
{
    public function __construct(
        private readonly LanguageResolver $languageResolver
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(CustomerInterface $customer): array
    {
        $item = [
            'email' => strtolower(trim((string)$customer->getEmail())),
            'external_id' => (string)$customer->getId(),
        ];

        $firstname = trim((string)$customer->getFirstname());
        if ($firstname !== '') {
            $item['first_name'] = $firstname;
        }
        $lastname = trim((string)$customer->getLastname());
        if ($lastname !== '') {
            $item['last_name'] = $lastname;
        }

        $country = $this->billingCountry($customer);
        if ($country !== null) {
            $item['country'] = $country;
        }

        $phone = $this->billingPhone($customer);
        if ($phone !== null) {
            $item['phone'] = $phone;
        }

        $language = $this->languageResolver->forStore($customer->getStoreId());
        if ($language !== '') {
            $item['language'] = $language;
        }

        $createdAt = (string)$customer->getCreatedAt();
        if ($createdAt !== '') {
            $timestamp = strtotime($createdAt);
            if ($timestamp !== false) {
                $item['first_seen_at'] = gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
        }

        return $item;
    }

    private function billingPhone(CustomerInterface $customer): ?string
    {
        foreach ((array)$customer->getAddresses() as $address) {
            if ($address->isDefaultBilling()) {
                $phone = trim((string)$address->getTelephone());

                return $phone !== '' ? $phone : null;
            }
        }

        return null;
    }

    private function billingCountry(CustomerInterface $customer): ?string
    {
        foreach ((array)$customer->getAddresses() as $address) {
            if ($address->isDefaultBilling()) {
                $country = strtoupper(trim((string)$address->getCountryId()));

                return preg_match('/^[A-Z]{2}$/', $country) === 1 ? $country : null;
            }
        }

        return null;
    }
}
