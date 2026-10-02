<?php
/**
 * Copyright © Smaily. All rights reserved.
 * See LICENSE.txt for license details.
 */

declare(strict_types=1);

namespace Smaily\Connect\Model\AbandonedCart;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Validator\EmailAddress;
use Magento\Store\Model\StoreManagerInterface;
use Smaily\Connect\Api\GuestCartEmailInterface;
use Smaily\Connect\Model\Config;

/**
 * Magento's checkout keeps a guest's email in the browser until the payment
 * step, so a guest who leaves at the shipping step has no email on the cart
 * and the abandoned-cart scan cannot reach them. The checkout email field
 * (view/frontend/web/js/view/form/element/email-mixin.js) posts the address
 * here once it is valid, and this puts it on quote.customer_email — the
 * column Magento itself fills at the payment step (PRO-3693, the WooCommerce
 * plugin's guest-email capture).
 *
 * Only quote.customer_email is written, with one UPDATE on the checkout
 * connection: saving the quote through its model or repository would collect
 * totals and dispatch the quote save events for one text column. The UPDATE
 * repeats the guest and active conditions, so a cart that a login or an order
 * changed between the read and the write is left alone.
 *
 * The endpoint is anonymous, so it is an obvious way to put someone else's
 * address on carts. Three limits bound it, all in the application cache like
 * the browse relay's (a cache flush resets them): requests per connection
 * address (Magento's RemoteAddress — a forwarding header counts only where
 * the store's own configuration names it; an IPv6 address counts by its /64,
 * the block one subscriber is usually given), writes per cart, and accepted
 * writes per hour for the whole installation, which bounds a caller spread
 * over many addresses. Behind a proxy Magento is not told about, every
 * shopper has the proxy's address, so the per-address limit is then one
 * limit for the whole store. A reminder still reaches only a contact Smaily
 * already has (force_opt_in=false, AutomationHandler).
 */
class GuestCartEmail implements GuestCartEmailInterface
{
    public const RATE_LIMIT_PER_WINDOW = 30;
    public const RATE_WINDOW_SECONDS = 600;
    public const MAX_WRITES_PER_CART = 5;
    public const MAX_WRITES_PER_HOUR = 2000;
    public const STORE_WINDOW_SECONDS = 3600;

    /**
     * The cron reminds only carts younger than 24 h, so a cart's write count
     * needs to live no longer than that.
     */
    private const WRITES_TTL_SECONDS = 86400;

    /**
     * The width of quote.customer_email.
     */
    private const MAX_EMAIL_LENGTH = 255;

    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly EmailAddress $emailValidator,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CacheInterface $cache,
        private readonly DateTime $dateTime,
        private readonly RemoteAddress $remoteAddress
    ) {
    }

    /**
     * @inheritDoc
     */
    public function set(string $cartId, string $email): bool
    {
        if (!$this->allowRequest()) {
            return false;
        }

        $email = trim($email);
        if ($email === '' || strlen($email) > self::MAX_EMAIL_LENGTH || !$this->emailValidator->isValid($email)) {
            return false;
        }

        $quote = $this->loadGuestQuote($cartId);
        if ($quote === null || !$this->isAbandonedCartOn((int)$quote['store_id'])) {
            return false;
        }

        $quoteId = (int)$quote['entity_id'];
        if (strcasecmp((string)$quote['customer_email'], $email) === 0) {
            return true;
        }

        $writesKey = 'smaily_cart_email_writes_' . $quoteId;
        $writes = (int)$this->cache->load($writesKey);
        if ($writes >= self::MAX_WRITES_PER_CART) {
            return false;
        }
        $storeKey = 'smaily_cart_email_store_'
            . intdiv($this->dateTime->gmtTimestamp(), self::STORE_WINDOW_SECONDS);
        $storeWrites = (int)$this->cache->load($storeKey);
        if ($storeWrites >= self::MAX_WRITES_PER_HOUR) {
            return false;
        }

        $connection = $this->resourceConnection->getConnection('checkout');
        $updated = $connection->update(
            $this->resourceConnection->getTableName('quote', 'checkout'),
            ['customer_email' => $email],
            ['entity_id = ?' => $quoteId, 'customer_id IS NULL', 'is_active = ?' => 1]
        );
        if ($updated < 1) {
            return false;
        }
        $this->cache->save((string)($writes + 1), $writesKey, [], self::WRITES_TTL_SECONDS);
        $this->cache->save((string)($storeWrites + 1), $storeKey, [], 2 * self::STORE_WINDOW_SECONDS);

        return true;
    }

    /**
     * The guest cart behind a masked id, when it is active and has items.
     *
     * @return array<string, mixed>|null
     */
    private function loadGuestQuote(string $cartId): ?array
    {
        if ($cartId === '') {
            return null;
        }

        $connection = $this->resourceConnection->getConnection('checkout');
        $select = $connection->select()
            ->from(['mask' => $this->resourceConnection->getTableName('quote_id_mask', 'checkout')], [])
            ->join(
                ['quote' => $this->resourceConnection->getTableName('quote', 'checkout')],
                'quote.entity_id = mask.quote_id',
                ['entity_id', 'store_id', 'customer_email']
            )
            ->where('mask.masked_id = ?', $cartId)
            ->where('quote.customer_id IS NULL')
            ->where('quote.is_active = ?', 1)
            ->where('quote.items_count > ?', 0)
            ->limit(1);
        $row = $connection->fetchRow($select);

        return is_array($row) ? $row : null;
    }

    /**
     * The email is kept on the cart only where the abandoned-cart reminder
     * can use it.
     */
    private function isAbandonedCartOn(int $storeId): bool
    {
        try {
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        } catch (NoSuchEntityException) {
            return false;
        }

        return $this->config->isAbandonedCartEnabled($websiteId);
    }

    /**
     * Fixed-window counter per connection address, as the browse relay's.
     */
    private function allowRequest(): bool
    {
        $caller = $this->callerKey((string)$this->remoteAddress->getRemoteAddress());
        $window = intdiv($this->dateTime->gmtTimestamp(), self::RATE_WINDOW_SECONDS);
        $key = 'smaily_cart_email_' . sha1($caller) . '_' . $window;

        $count = (int)$this->cache->load($key);
        if ($count >= self::RATE_LIMIT_PER_WINDOW) {
            return false;
        }
        $this->cache->save((string)($count + 1), $key, [], 2 * self::RATE_WINDOW_SECONDS);

        return true;
    }

    /**
     * What the per-address limit counts by: an IPv4 address as it is, an
     * IPv6 address by its /64 — one subscriber holds a whole /64 and can
     * pick a fresh address in it for every request. An IPv4-mapped IPv6
     * address (::ffff:a.b.c.d) is its IPv4 address: its /64 is the same for
     * every IPv4 caller.
     */
    private function callerKey(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $ip;
        }
        $packed = (string)inet_pton($ip);
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string)inet_ntop(substr($packed, 12));
        }

        return bin2hex(substr($packed, 0, 8)) . '/64';
    }
}
