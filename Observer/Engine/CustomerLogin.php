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
 *
 * The same consenting login puts the customer in the Campaign Intelligence
 * customer scope (contract v1.23.0 §4 group c; CON-262): the merge row
 * carries IdentityMergeHandler::SEND_CUSTOMER, and the handler sends the
 * customer (§4) right before the merge (§7 "Send the customer first"),
 * also when the customer never bought and is not in the contact sync.
 * Every consenting login does, not only one outside the other two groups:
 * the store cannot tell that the engine already has the customer (an
 * account from before the connection, from an ImportExport import), and
 * the row is an idempotent upsert, one per merge. A login whose merge
 * cannot be queued (no session cookie, and no token cookie could be set)
 * sends no customer either: the customer is sent for the merge.
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
        $consenting = $this->isConsentingLogin();
        $visitorToken = $cookies['visitor_token'] ?? ($consenting ? $this->storeVisitorToken->issue() : null);
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
        if ($consenting) {
            // Contract §4 group c: the customer goes first; stripped before
            // the call to the engine.
            $payload[IdentityMergeHandler::SEND_CUSTOMER] = true;
        }

        $this->eventQueue->enqueue(
            EventType::ENGINE_IDENTITY_MERGE,
            $payload,
            (string)$customer->getId()
        );
    }

    /**
     * A login with marketing consent at a store view whose own pages the
     * shopper logs in on: such a shopper without a visitor token gets a
     * store-created one (CON-260), and the customer is sent before the
     * merge (CON-262).
     */
    private function isConsentingLogin(): bool
    {
        return $this->marketingConsent->isGiven() && !$this->config->hasStorefrontUrl();
    }
}
