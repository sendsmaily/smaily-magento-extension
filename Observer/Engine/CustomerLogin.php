<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Observer\Engine;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;

/**
 * Identity merge on login: binds the anonymous session/visitor cookies to
 * the now-known customer, on a login through Magento's own pages or a
 * customer token request (whose cookies a separate storefront forwards).
 * Not on an admin's "Login as Customer": those cookies are the admin's own.
 * Queued (never blocks login) — the handler calls
 * POST identity/merge with the engine's retry policy.
 */
class CustomerLogin implements ObserverInterface
{
    /**
     * Front name of Magento's "Login as Customer" storefront page
     * (Magento_LoginAsCustomerFrontendUi, loginascustomer/login/index), where
     * the admin's browser is logged in as the customer. Matched by route, not
     * through the module's API: the module is optional, and it records the
     * admin on the session only after customer_login has fired.
     */
    private const LOGIN_AS_CUSTOMER_ROUTE = 'loginascustomer';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly Settings $settings,
        private readonly AttributionManager $attributionManager,
        private readonly EventQueue $eventQueue
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        if (!$this->settings->isConnected()
            || $this->request->getModuleName() === self::LOGIN_AS_CUSTOMER_ROUTE
        ) {
            return;
        }

        // Magento's own login passes the customer model; a customer token
        // login (GraphQL generateCustomerToken, REST integration/customer/token)
        // passes the customer data object.
        $customer = $observer->getEvent()->getData('customer');
        if (!($customer instanceof Customer || $customer instanceof CustomerInterface) || !$customer->getEmail()) {
            return;
        }

        $cookies = $this->attributionManager->readCookies();
        if ($cookies['anon_session_id'] === null && $cookies['visitor_token'] === null) {
            return;
        }

        $payload = [
            'customer_email' => strtolower(trim((string)$customer->getEmail())),
            'customer_external_id' => (string)$customer->getId(),
            'merge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'merge_reason' => 'login',
            // The handler's consent check reads at this store view; stripped
            // before the call to the engine.
            'store_id' => (int)$customer->getStoreId(),
        ];
        if ($cookies['anon_session_id'] !== null) {
            $payload['anon_session_id'] = $cookies['anon_session_id'];
        }
        if ($cookies['visitor_token'] !== null) {
            $payload['smaily_visitor_token'] = $cookies['visitor_token'];
        }

        $this->eventQueue->enqueue(
            EventType::ENGINE_IDENTITY_MERGE,
            $payload,
            (string)$customer->getId()
        );
    }
}
