<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\Engine;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Recommendation attribution: campaign-click URL params are written into
 * first-party cookies by the storefront script (client-side, so Full Page
 * Cache cannot swallow the capture); at order placement the cookies are
 * stamped into the smaily_order_attribution side table, which the order
 * payload builder forwards to the engine.
 *
 * Cookie names come from the tenant's setup-exchange config with the
 * cross-platform defaults below. Attribution is deliberately NOT gated on
 * analytics consent (first-party functional cookie for the merchant's own
 * email click), matching the Woo/Shopify behavior.
 *
 * Nothing is stamped on an order an admin places with "Login as Customer":
 * the browser's cookies are then the admin's own.
 */
class AttributionManager
{
    public const DEFAULT_COOKIE_VISITOR = 'smaily_rec_uid';
    public const DEFAULT_COOKIE_SESSION = 'smaily_anon_sid';
    public const DEFAULT_COOKIE_REC_ID = 'smaily_rec_id';
    public const DEFAULT_COOKIE_CONTEXT = 'smaily_rec_ctx';

    public const DEFAULT_URL_PARAM_VISITOR = 'smaily_vt';
    public const DEFAULT_URL_PARAM_REC_ID = 'smaily_rec';
    public const DEFAULT_URL_PARAM_CONTEXT = 'smaily_ctx';

    private const ATTRIBUTION_TABLE = 'smaily_order_attribution';

    /**
     * Magento's "Login as Customer" (optional: no composer or module.xml
     * dependency), resolved by name only while the module is enabled. Its
     * API answers the admin id held in the customer session, the same check
     * Magento_LoginAsCustomerSales marks such an order with. A string, not
     * ::class: the interface may be absent.
     */
    private const LOGIN_AS_CUSTOMER_MODULE = 'Magento_LoginAsCustomer';
    private const LOGGED_AS_CUSTOMER_ADMIN_ID = 'Magento\\LoginAsCustomerApi\\Api\\GetLoggedAsCustomerAdminIdInterface';

    public function __construct(
        private readonly Settings $settings,
        private readonly CookieManagerInterface $cookieManager,
        private readonly ResourceConnection $resourceConnection,
        private readonly ModuleManager $moduleManager,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Cookie/URL-param names for the storefront script (engine config wins).
     *
     * @return array<string, string|int>
     */
    public function getClientConfig(): array
    {
        $config = $this->settings->getEngineConfig();

        return [
            'cookieVisitor' => (string)($config['tracking_cookie_name'] ?? self::DEFAULT_COOKIE_VISITOR),
            'cookieSession' => (string)($config['session_cookie_name'] ?? self::DEFAULT_COOKIE_SESSION),
            'cookieRecId' => (string)($config['rec_id_cookie_name'] ?? self::DEFAULT_COOKIE_REC_ID),
            'cookieContext' => (string)($config['context_cookie_name'] ?? self::DEFAULT_COOKIE_CONTEXT),
            'paramVisitor' => (string)($config['url_param_visitor_token'] ?? self::DEFAULT_URL_PARAM_VISITOR),
            'paramRecId' => (string)($config['url_param_rec_id'] ?? self::DEFAULT_URL_PARAM_REC_ID),
            'paramContext' => (string)($config['url_param_context'] ?? self::DEFAULT_URL_PARAM_CONTEXT),
            'ttlVisitorDays' => (int)($config['cookie_ttl_days'] ?? 365),
            'ttlSessionDays' => (int)($config['session_ttl_days'] ?? 30),
            'ttlRecIdDays' => (int)($config['rec_id_ttl_days'] ?? 30),
            'ttlContextDays' => (int)($config['context_ttl_days'] ?? 30),
        ];
    }

    /**
     * Read the attribution cookies from the current request.
     *
     * Each value is shape-checked on its own (RecId, AttributionShape): an
     * off-shape cookie reads as absent, so it can neither fail the side-table
     * insert (its rec id, visitor token and session id columns hold 64
     * characters) nor be stored cut short, and
     * the order keeps every other signal (PRO-3584).
     *
     * @return array{rec_id: ?string, visitor_token: ?string, rec_ctx: ?string, anon_session_id: ?string}
     */
    public function readCookies(): array
    {
        $names = $this->getClientConfig();

        return [
            'rec_id' => $this->cookie((string)$names['cookieRecId'], RecId::isValid(...)),
            'visitor_token' => $this->cookie((string)$names['cookieVisitor'], AttributionShape::isVisitorToken(...)),
            'rec_ctx' => $this->cookie((string)$names['cookieContext'], AttributionShape::isContext(...)),
            'anon_session_id' => $this->cookie((string)$names['cookieSession'], AttributionShape::isSessionId(...)),
        ];
    }

    /**
     * Stamp the current attribution cookies onto a placed order.
     *
     * Not on an order an admin places with "Login as Customer".
     */
    public function saveForOrder(int $orderId): void
    {
        if ($orderId <= 0 || !$this->settings->isConnected()) {
            return;
        }

        $cookies = $this->readCookies();
        if (!array_filter($cookies) || $this->isLoginAsCustomer()) {
            return;
        }

        $connection = $this->resourceConnection->getConnection('sales');
        $connection->insertOnDuplicate(
            $this->resourceConnection->getTableName(self::ATTRIBUTION_TABLE, 'sales'),
            [
                'order_id' => $orderId,
                'rec_id' => $cookies['rec_id'],
                'visitor_token' => $cookies['visitor_token'],
                'rec_ctx' => $cookies['rec_ctx'],
                'anon_session_id' => $cookies['anon_session_id'],
            ],
            ['rec_id', 'visitor_token', 'rec_ctx', 'anon_session_id']
        );
    }

    /**
     * Whether an admin is logged in as the customer ("Login as Customer").
     */
    private function isLoginAsCustomer(): bool
    {
        if (!$this->moduleManager->isEnabled(self::LOGIN_AS_CUSTOMER_MODULE)) {
            return false;
        }

        return (int)$this->objectManager->get(self::LOGGED_AS_CUSTOMER_ADMIN_ID)->execute() > 0;
    }

    /**
     * The trimmed cookie value, or null when it is absent or off-shape.
     *
     * @param string $name
     * @param callable(string): bool $isWellFormed
     * @return string|null
     */
    private function cookie(string $name, callable $isWellFormed): ?string
    {
        if ($name === '') {
            return null;
        }
        $value = $this->cookieManager->getCookie($name);
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $isWellFormed($value) ? $value : null;
    }
}
