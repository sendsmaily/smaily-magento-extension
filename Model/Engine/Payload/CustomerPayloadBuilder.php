<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine\Payload;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Smaily\Connect\Model\Engine\Connection;
use Smaily\Connect\Model\Engine\Websites;
use Smaily\Connect\Model\Logger\Logger;
use Smaily\Connect\Model\Multilingual\LanguageResolver;

/**
 * Customer -> WireCustomer (contract §4). Identity is the lowercased email;
 * NO consent fields ever — the engine is a separate lawful surface from
 * Smaily marketing consent. One row per customer account, with the code of
 * the account's website (v1.21.0) when that website is one of the
 * Campaign Intelligence connection's the row is for (CON-237).
 */
class CustomerPayloadBuilder
{
    public function __construct(
        private readonly LanguageResolver $languageResolver,
        private readonly Websites $websites,
        private readonly Logger $logger
    ) {
    }

    /**
     * The customer's row for a connection's engine store. Without one, the
     * row names the account's website whatever its connection — for a
     * caller that has not yet been given its connection (the login merge,
     * MergeCustomerSender), which sends to the primary.
     *
     * @return array<string, mixed>
     */
    public function build(CustomerInterface $customer, ?Connection $connection = null): array
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

        $language = $this->language($customer);
        if ($language !== '') {
            $item['language'] = $language;
        }

        // The account's website (contract v1.21.0 §4, CON-253): with
        // Share Customer Accounts = Per Website one email has an account on
        // each website, and the engine keeps each website's id and language.
        // A website of another engine store is not this one's to name
        // (CON-237): its engine store does not know it.
        $websiteId = (int)$customer->getWebsiteId();
        $website = $connection === null || $connection->servesWebsite($websiteId)
            ? $this->websites->code($websiteId)
            : null;
        if ($website !== null) {
            $item['website'] = $website;
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

    /**
     * The language of the customer's store view. A customer can keep the id
     * of a store view that no longer exists, and Magento then throws instead
     * of reading the locale; the customer goes without a language, and the
     * extension's log names it by its id (CON-227).
     */
    private function language(CustomerInterface $customer): string
    {
        try {
            return $this->languageResolver->forStore($customer->getStoreId());
        } catch (NoSuchEntityException) {
            $this->logger->error('Customer sent without a language: its store view no longer exists', [
                'customer_id' => (int)$customer->getId(),
                'store_id' => (int)$customer->getStoreId(),
            ]);

            return '';
        }
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
