# Upgrading from Smaily for Magento 2.8.x

Version 3 is a ground-up rewrite (module `Smaily_Connect`, replacing
`Smaily_SmailyForMagento`), but upgrading is a normal composer update — the
package name is unchanged and your settings migrate automatically.

## Before you upgrade: what to note down

Write down your 2.8.x settings first, so that you can compare them with
v3 after the upgrade (the upgrade deletes the 2.8.x values, see below).
Open **Stores > Configuration > Smaily Email Marketing and Automation >
Module Configuration** and read the fields below at **Default Config**,
then at **each website** in the scope switcher. The 2.8.x admin has no
store-view settings. In v3, compare them under **Smaily Connect >
Settings** with the same website selected.

| 2.8.x group > field | Read at | In v3 |
|---|---|---|
| General Settings > Enable Module | default, each website | **Not carried over** — see *Set again after the upgrade* |
| General Settings > Subdomain, API Username | default, each website | Settings > Connection, same scope. The API Password carries over too (encrypted); you do not need to note it |
| Newsletter Subscription Form > Enable Subscribers Collection, Autoresponder ID | default, each website | Welcome automation, same scope: on when both are set |
| Newsletter Subscription Form > Enable CAPTCHA, CAPTCHA Type | default, each website | Not carried over — see *Captcha* below |
| Subscribers Syncronization > Enable Syncronization, Syncronize Additional Fields | default, each website | Subscriber Synchronization, same scope (Gender is sent as `user_gender`) |
| Subscribers Syncronization > Frequency | default | Not carried over — v3 syncs as changes happen |
| Abandoned Cart > Enable Abandoned Cart, Autoresponder ID, Trigger Abandoned Cart Automation | default, each website | Automations, same scope |
| Abandoned Cart > Add Template Parameters | default, each website | Not carried over — every product field is sent |

Also note what in Smaily depends on the store's data, because some of it
changes (see *Behavior changes to review after upgrading*): segments or
templates that use the `gender` field, and RSS feed URLs in your templates
that use the `category` parameter. Check that `bin/magento cron:run` runs
every minute on the server. Note which installed modules send
abandoned-cart emails today (for example Mageplaza SMTP, Avada Email
Marketing, or Adobe Commerce's own email reminder rules), and switch those
emails off before you switch the Smaily abandoned-cart automation on, so a
shopper does not get two reminders for one cart.

**Values saved at store-view scope.** 2.8.x reads every setting per
website, so a `smaily/*` value at store-view scope (possible only through
`bin/magento config:set --scope=stores`) has no effect there. List them
before you upgrade:

```sql
SELECT scope_id, path FROM core_config_data
WHERE path LIKE 'smaily/%' AND scope = 'stores';
```

The upgrade carries such a value over at its store view. v3 reads the Smaily
account (subdomain, username, password) per store view, so a store-view
`smaily/general/*` value **replaces the website's account for that store
view** after the upgrade. Delete those rows before you upgrade. v3 reads
every other setting per website, as 2.8.x does: those store-view values
stay without effect.

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

Each value keeps the scope it was saved at: a website's own value stays
that website's, and a website without one keeps using the default. One
Smaily account saved at Default Config serves every website and store view.

Once the settings are migrated, the upgrade deletes the old 2.8.x settings
(every `smaily/*` config row, at every scope — the plain-text password
among them). The migrated settings live under `smaily_connect/*` and are not
touched. A store that has no 2.8.x settings is not affected.

**Going back to 2.8.x starts with empty settings.** The upgrade cannot be
undone by changing the composer version constraint alone: after a downgrade,
enter the Smaily subdomain, username and password and the 2.8.x options
again. If you may need to go back, note the 2.8.x settings (see *Before
you upgrade*) or take a database backup before you upgrade.

## Set again after the upgrade

- **Enable Module.** v3 has no module switch, and the upgrade does not read
  it. A website (or Default Config) where 2.8.x had *Enable Module = No*
  starts its subscriber sync, welcome and abandoned-cart automation in v3
  if those settings are on at that scope or inherited from Default Config.
  Right after `setup:upgrade`, switch them off for that website in
  **Smaily Connect > Settings**.

## What is cleaned up

- The legacy `reminder_date` / `is_sent` columns on the core `quote` table
  and the unused `smaily_customer_sync` table are dropped. Already-mailed
  abandoned carts are carried over first — nobody gets a duplicate
  reminder because of the upgrade.
- The old 2.8.x settings (`smaily/*`, every scope) are deleted once they are
  migrated; see above.
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
