<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Smaily\Connect\Model\Privacy\ProfilingConsent;

/**
 * View model for the customer personalization preference form.
 */
class PrivacyForm implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly ProfilingConsent $profilingConsent
    ) {
    }

    /**
     * The shopper's choice where the store knows it, null where it does not
     * (PRO-3591): the page then offers only an opt-out.
     */
    public function getKnownPreference(): ?bool
    {
        if (!$this->customerSession->isLoggedIn()) {
            return null;
        }
        $customer = $this->customerSession->getCustomerData();

        return $this->profilingConsent->knownPreference(
            (string)$customer->getEmail(),
            $customer->getStoreId()
        );
    }
}
