# Upgrading from Smaily for Magento 2.8.x

Version 3 is a ground-up rewrite (module `Smaily_Connect`, replacing
`Smaily_SmailyForMagento`), but upgrading is a normal composer upgrade — the
package name is unchanged and your settings migrate automatically.

## Before you upgrade: what to note down

Write down your 2.8.x settings first, so that you can compare them with
v3 after the upgrade (the upgrade deletes the 2.8.x values, see below).
Open **Stores > Configuration > Smaily Email Marketing and Automation >
Module Configuration** and read the fields below at **Default Config**,
then at **each website** in the scope switcher. The 2.8.x admin has no
store-view settings. In v3, compare them in the initial setup and under
**Smaily Connect > Settings** with the same website selected (see
*After the upgrade: the initial setup*).

| 2.8.x group > field | Read at | In v3 |
|---|---|---|
| General Settings > Enable Module | default, each website | *No* switches Subscriber Synchronization, the welcome automation and the abandoned-cart automation off, same scope — see *What migrates automatically* |
| General Settings > Subdomain, API Username | default, each website | Settings > Connection, same scope. The API Password carries over too (encrypted); you do not need to note it |
| Newsletter Subscription Form > Enable Subscribers Collection, Autoresponder ID | default, each website | Welcome automation, same scope: on where both are set — a website's own value, else the Default Config value |
| Newsletter Subscription Form > Enable CAPTCHA, CAPTCHA Type | default, each website | Not carried over — see *Captcha* below |
| Subscribers Syncronization > Enable Syncronization, Syncronize Additional Fields | default, each website | Subscriber Synchronization, same scope (Gender is sent as `user_gender`) |
| Subscribers Syncronization > Frequency | default | Not carried over — v3 syncs as changes happen |
| Abandoned Cart > Enable Abandoned Cart, Autoresponder ID, Trigger Abandoned Cart Automation | default, each website | Automations, same scope: on where Enable Abandoned Cart and an Autoresponder ID are both set — a website's own value, else the Default Config value |
| Abandoned Cart > Add Template Parameters | default, each website | Not carried over — every product field is sent |

Also note what in Smaily depends on the store's data, because some of it
changes (see *What changes on upgrade day* and *Behavior changes to review
after upgrading*): segments or templates that use the `gender` or `name`
field, and RSS feed URLs in your templates that use the `category`
parameter. Check that `bin/magento cron:run` runs
every minute on the server. Note which installed modules send
abandoned-cart emails today (for example Mageplaza SMTP, Avada Email
Marketing, or Adobe Commerce's own email reminder rules), and switch those
emails off before you switch the Smaily abandoned-cart automation on, so a
shopper does not get two reminders for one cart.

**Values saved at store-view scope.** 2.8.x reads every setting per
website, so a `smaily/*` value at store-view scope (possible only through
`bin/magento config:set --scope=stores`) has no effect there. The upgrade
keeps it that way, so there is nothing to do before you upgrade:

- A store-view Smaily account value (Subdomain, API Username, API
  Password) is **not carried over**: every store view uses its website's
  Smaily account, as in 2.8.x. An admin notice (*Smaily Connect upgrade:
  store-view Smaily account not carried over*) names each store view
  whose account value was left out, with its website.
- A store-view *Enable Module* value is not carried over either.
- Every other store-view value is carried over at its store view. v3 reads
  those settings per website, as 2.8.x does, so they stay without effect.

## Steps

Check the Magento and PHP versions first: version 3 needs Magento
**2.4.4 or newer** and PHP **8.1 – 8.4** (see
[INSTALLING.md](INSTALLING.md#before-you-start)). Run every command below
from the Magento root, as the user that owns the Magento files.

`composer update smaily/smailyformagento` alone keeps 2.8.x: the
constraint that the 2.8.x install wrote into `composer.json` (`^2.8`) does
not allow version 3. Require version 3 instead, as below. Until 3.0.0 is
released, give the release candidate's exact version, as the
[releases page](https://github.com/sendsmaily/smaily-magento-extension/releases)
names it — for example `composer require smaily/smailyformagento:3.0.0-rc10`.
`^3.0` does not install a release candidate on a standard Magento project,
which accepts stable versions only.

**Production mode** (check with `bin/magento deploy:mode:show`):

```bash
bin/magento maintenance:enable
composer require smaily/smailyformagento:^3.0
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy en_US   # list every locale your admin and storefront use, e.g. en_US et_EE
bin/magento maintenance:disable
bin/magento cache:flush
```

Keep maintenance mode on until the last command. `setup:upgrade` empties
`pub/static`, and until `setup:static-content:deploy` has run, every
storefront and admin page fails with "Unable to retrieve deployment
version of static files".

**Developer or default mode:**

```bash
composer require smaily/smailyformagento:^3.0
bin/magento setup:upgrade
bin/magento cache:flush
```

The old module is replaced by `Smaily_Connect` in the same package:
`setup:upgrade` enables `Smaily_Connect` (no `module:enable` is needed)
and runs the migration. `bin/magento module:status Smaily_Connect` then
answers `Module is enabled`.

## After the upgrade: the initial setup

Every **Marketing > Smaily Connect** page (Dashboard, Settings, Log) opens
**Initial setup** until the initial setup is finished for the website
selected. On a store with more than one website, it first asks which
website to set up; run it once for each website. The migrated settings are
filled in: when the website's Smaily account carried over, the setup opens
on the Contacts step, and the Automations step shows the migrated
workflows and wait time. Compare each step with your notes, in
particular each automation's *Enabled* tick, then finish the setup.

Finishing the Contacts step switches contact synchronization on for that
website. For a website where 2.8.x had *Enable Module = No*, switch it off
again afterwards: untick **Sync contacts to Smaily** under **Settings >
Contacts** and save.

## What migrates automatically

| Legacy setting | Where it lands in v3 |
|---|---|
| API subdomain / username / password (all websites) | API Connection (the previously **plaintext** password is now stored **encrypted**; the subdomain is normalized) |
| Newsletter opt-in autoresponder (`workflowId`) | Welcome automation (on at each website where 2.8.x sent the opt-in email: opt-in triggering on and an autoresponder set, each the website's own value else the Default Config value) + a fallback row in the automation mapping table |
| Subscriber cron sync toggle + field selection | Subscriber Synchronization (every tick carries over) |
| Abandoned cart toggle / autoresponder / interval | Automations group (on at each website where 2.8.x sent the reminder: the toggle on and an autoresponder set, each the website's own value else the Default Config value; `2:hour` → 120 minutes) + a mapping fallback row |
| *Enable Module = No* (Default Config or a website) | Subscriber Synchronization, the welcome automation and the abandoned-cart automation **off** at that scope. *Yes*, or no saved value, changes nothing — except under a Default Config with *No*, see below |

Each value keeps the scope it was saved at: a website's own value stays
that website's, and a website without one keeps using the default. One
Smaily account saved at Default Config serves every website and store view.
A website without its own *Enable Module* value therefore inherits the
switched-off settings of a Default Config with *No*. A website with its
own *Enable Module = Yes* under a Default Config with *No* runs as it did
in 2.8.x: the upgrade saves the three settings at that website with the
value they had there — the website's own 2.8.x value, else the Default
Config value, else the v3 default (contact sync on, welcome and abandoned
cart off).

Once the settings are migrated, the upgrade deletes the old 2.8.x settings
(every `smaily/*` config row, at every scope — the plain-text password
among them). The migrated settings live under `smaily_connect/*` and are not
touched. A store that has no 2.8.x settings is not affected.

**Going back to 2.8.x starts with empty settings.** The upgrade cannot be
undone by changing the composer version constraint alone: after a downgrade,
enter the Smaily subdomain, username and password and the 2.8.x options
again. If you may need to go back, note the 2.8.x settings (see *Before
you upgrade*) or take a database backup before you upgrade.

## What changes on upgrade day

Two v3 defaults apply from the first request after `setup:upgrade`. Both
are per website, on **Marketing > Smaily Connect > Settings > Contacts**
(and in step 2 of the initial setup):

- **The checkout newsletter checkbox is on.** 2.8.x had no checkout
  checkbox. v3 adds one to the checkout payment step while contact sync is
  on and the store view is connected to Smaily. To keep checkout as it
  was, untick **Show a newsletter checkbox at checkout**.
- **Magento's own newsletter emails are suppressed.** Magento's
  subscription-confirmed ("confirmation success") email and its
  unsubscribe email are not sent for a store view connected to Smaily.
  2.8.x suppressed them only on a website with *Enable Subscribers
  Collection* on. Magento's double opt-in confirmation *request* email is
  always sent. To have Magento send them again, untick **Let Smaily send
  the opt-in confirmation emails (suppresses Magento's own)**.

The contact fields Smaily receives change too:

- **`name` is not sent any more.** 2.8.x sent a customer's full name as
  `name` (*First Last*). v3 sends the first name as `first_name` and
  the last name as `last_name`, when **First Name** and **Last Name** are
  ticked under **Extra fields to sync with each contact** (Settings >
  Contacts; the 2.8.x ticks carry over, so tick them if you did not sync
  them before). A Smaily template or segment that uses `name` must use
  `first_name` and `last_name` instead. Smaily keeps the `name` values it
  has, but they are no longer updated.
- **First and last names are sent as stored.** 2.8.x upper-cased the
  first letter of `first_name` and `last_name` (*mari* became *Mari*). v3
  sends them as they are saved on the customer account, so a name saved
  in lower case reaches Smaily in lower case.
- **An unknown gender is left out.** 2.8.x sent `Male` for a customer
  whose gender is not set or is *Not Specified*. v3 sends `user_gender`
  (see *Behavior changes to review after upgrading*) only for `Male` and
  `Female`, and leaves the field out for every other customer. A segment
  on `Male` therefore no longer includes customers without a gender.
- **`store` and `store_group` are sent only when the store view is
  known.** 2.8.x always sent `store`, `store_group` and `store_website`,
  with an empty `store` and `store_group` when it could not find the
  contact's store view. v3 sends the three fields with the names of the
  contact's store view, store and website, and leaves all three out when
  that store view cannot be found, so Smaily keeps the values it has.
- **Birthday is sent as `YYYY-MM-DD`** (for example `1990-05-17`); 2.8.x
  sent `1990-05-17 00:00:00`.
- **An empty value is left out instead of sent empty.** 2.8.x sent `''`
  for a selected field the contact has no value for, which wiped the value
  in Smaily. v3 leaves the field out, so Smaily keeps the value it has.
- **New field `language`:** the language of the contact's store view, from
  its locale (for example `et` for `et_EE`), on every contact.

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
