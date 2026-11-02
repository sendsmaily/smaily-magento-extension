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
use Smaily\Connect\Model\Config;
use Smaily\Connect\Model\Engine\AttributionManager;
use Smaily\Connect\Model\Engine\MarketingConsent;
use Smaily\Connect\Model\Engine\Settings;
use Smaily\Connect\Model\Engine\StoreVisitorToken;
use Smaily\Connect\Model\Queue\EventQueue;
use Smaily\Connect\Model\Queue\EventType;
use Smaily\Connect\Model\Queue\Handler\IdentityMergeHandler;

/**
 * Identity merge on login: binds the anonymous session/visitor cookies to
 * the now-known customer, on a login through Magento's own pages or a
 * customer token request (whose cookies a separate storefront forwards).
 * Not on an admin's "Login as Customer": those cookies are the admin's own.
 * Queued (never blocks login) — the handler calls
 * POST identity/merge with the engine's retry policy.
 *
 * It sends the visitor token as gated by `AttributionManager::readCookies()`.
 *
 * A shopper with marketing consent (MarketingConsent, the rule a
 * store-created token travels by, CON-181) and no visitor token gets one
 * (contract §7 "Store-created token at login", v1.22.0; CON-260): a `vs_`
 * token is created, set in the visitor-token cookie from the server and
 * sent in this merge, so after logout the storefront can still ask for the
 * shopper's recommendations (§15). A visitor token the browser already
 * holds is kept and sent, never replaced, so each login of a consenting
 * shopper renews it. Nothing clears the cookie at logout (Erkki,
 * 2026-11-02). Without consent nothing is created and no store-created
 * token is sent. Not at a store view with a Storefront URL saved: a separate
 * storefront owns its shoppers' cookies and its own consent banner decides
 * (PRO-3918), so a token is sent there only when that storefront forwards
 * one.
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
        private readonly EventQueue $eventQueue,
        private readonly MarketingConsent $marketingConsent,
        private readonly StoreVisitorToken $storeVisitorToken,
        private readonly Config $config
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
        $visitorToken = $cookies['visitor_token'] ?? $this->createVisitorToken();
        if ($cookies['anon_session_id'] === null && $visitorToken === null) {
            return;
        }

        $payload = [
            'customer_email' => strtolower(trim((string)$customer->getEmail())),
            'customer_external_id' => (string)$customer->getId(),
            'merge_ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'merge_reason' => IdentityMergeHandler::MERGE_REASON_LOGIN,
            // The handler's consent check reads at this store view; stripped
            // before the call to the engine.
            'store_id' => (int)$customer->getStoreId(),
        ];
        if ($cookies['anon_session_id'] !== null) {
            $payload['anon_session_id'] = $cookies['anon_session_id'];
        }
        if ($visitorToken !== null) {
            $payload['smaily_visitor_token'] = $visitorToken;
        }

        $this->eventQueue->enqueue(
            EventType::ENGINE_IDENTITY_MERGE,
            $payload,
            (string)$customer->getId()
        );
    }

    /**
     * A new store-created token in the visitor-token cookie, for a shopper
     * with marketing consent at a store view whose own pages the shopper
     * logs in on; else null.
     */
    private function createVisitorToken(): ?string
    {
        if (!$this->marketingConsent->isGiven() || $this->config->hasStorefrontUrl()) {
            return null;
        }

        return $this->storeVisitorToken->issue();
    }
}
