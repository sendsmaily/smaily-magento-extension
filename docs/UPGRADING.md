# Upgrading from Smaily for Magento 2.8.x

Version 3 is a ground-up rewrite (module `Smaily_Connect`, replacing
`Smaily_SmailyForMagento`), but upgrading is a normal composer update — the
package name is unchanged and your settings migrate automatically.

## Steps

```bash
composer update smaily/smailyformagento
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento cache:flush
```

That's it. The old module is replaced by `Smaily_Connect` in the same
package; `setup:upgrade` runs the migration.

## What migrates automatically

| Legacy setting | Where it lands in v3 |
|---|---|
| API subdomain / username / password (all websites) | API Connection (the previously **plaintext** password is now stored **encrypted**; the subdomain is normalized) |
| Newsletter opt-in autoresponder (`workflowId`) | Welcome automation (enabled if opt-in triggering was enabled) + a fallback row in the automation mapping table |
| Subscriber cron sync toggle + field selection | Subscriber Synchronization (every tick carries over) |
| Abandoned cart toggle / autoresponder / interval | Automations group (`2:hour` → 120 minutes) + a mapping fallback row |

Legacy `smaily/*` config rows are left in place, so downgrading back to
2.8.x (composer version constraint) restores the old behavior. Uninstalling
Smaily Connect removes them along with its own settings (see
[INSTALLING.md](INSTALLING.md#disabling-or-removing-the-module)).

## What is cleaned up

- The legacy `reminder_date` / `is_sent` columns on the core `quote` table
  and the unused `smaily_customer_sync` table are dropped. Already-mailed
  abandoned carts are carried over first — nobody gets a duplicate
  reminder because of the upgrade.
- The orphaned dynamic cron-expression config row is removed.
- The stored value of the retired *Automations May Re-Subscribe (Advanced)*
  setting (`smaily_connect/subscribers/automation_force_opt_in`, a 3.0.0
  release-candidate setting) is deleted at every scope. No other setting is
  touched.

## Behavior changes to review after upgrading

- **Sync frequency presets are gone.** v3 syncs in near-real-time
  (observers + durable queue) with a 15-minute Smaily→Magento consent
  reconcile. An admin notice reminds you of this once after the upgrade.
- **Contact sync mode** defaults to *Subscribers only (consent)* — exactly
  the audience the legacy cron synced, so the upgrade never broadens your
  audience. Review the new modes if you want a different lawful basis.
- **Gender is sent as `user_gender`** (2.8.x sent it as `gender`), matching
  the field name Smaily's WooCommerce plugin uses so the same shopper never
  lands in two different fields. Your Synchronized Fields tick
  carries over automatically; if a Smaily segment or template references
  `gender`, repoint it to `user_gender` after the first sync. The values are
  unchanged (`Male` / `Female`).
- **Captcha:** the legacy custom captcha integration is replaced by
  Magento's native reCAPTCHA (Stores > Configuration > Security >
  Google reCAPTCHA Storefront). Enable it there if you used the old
  captcha options.
- **RSS feed:** the `category` parameter now takes a category **ID**
  (previously a fuzzy name match); only catalog-visible products are
  listed, variants resolve to their parent. Update feed URLs in your
  Smaily templates if you used category filtering.
- **Abandoned cart:** `{{abandoned_cart_url}}` is now a working cart
  recovery link. Prices in product fields remain tax-inclusive. The product
  **field selection is gone** — every product field is always sent, and all
  ten slots ride every reminder (the unused ones empty, which is what clears
  a previous cart from the contact). A legacy selection is simply not
  carried over.
- **Cron:** jobs moved into a dedicated `smaily_connect` cron group running
  in a separate process. Ensure `bin/magento cron:run` executes every
  minute for near-real-time delivery.
- **Automations never re-subscribe.** A welcome, first-order or
  abandoned-cart trigger never overrides an unsubscribe the contact made in
  Smaily, in any contact sync mode (2.8.x behaved the same). A 3.0.0
  release-candidate install that turned on the former *Automations May
  Re-Subscribe (Advanced)* setting loses its stored value in the update
  (see *What is cleaned up*): its triggers honour every unsubscribe, and
  contacts keep syncing with their real subscription state. There is
  nothing to do.

## New in v3 (nothing to configure unless you want it)

Contact sync modes, two-way consent sync, welcome/first-order automations,
checkout opt-in checkbox, event log with retry, historical imports,
multilingual routing and the optional Campaign Intelligence integration —
see the [User Guide](USER_GUIDE.md).
