# Smaily Connect for Magento 2 — User Guide

Smaily Connect keeps your newsletter audience, marketing automations and
(optionally) Smaily Campaign Intelligence in sync with your Magento store.

- [Installation](#installation)
- [Finding your way around](#finding-your-way-around)
- [Connecting your Smaily account](#connecting-your-smaily-account)
- [Contact synchronization](#contact-synchronization)
- [Automations](#automations)
- [Abandoned cart](#abandoned-cart)
- [Third-party one-step checkouts](#third-party-one-step-checkouts)
- [Product RSS feed](#product-rss-feed)
- [Campaign Intelligence](#campaign-intelligence)
- [Historical import (backfill)](#historical-import-backfill)
- [The log and troubleshooting](#the-log-and-troubleshooting)
- [Privacy and GDPR](#privacy-and-gdpr)
- [CLI reference](#cli-reference)
- [FAQ](#faq)

---

## Installation

### Composer (recommended)

```bash
composer require smaily/smailyformagento
bin/magento module:enable Smaily_Connect
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento cache:flush
```

### Manual

Extract the release ZIP to `app/code/Smaily/Connect` and run the same
`module:enable` / `setup:upgrade` steps. Every release also publishes a
`.sha256` file beside the ZIP; run
`sha256sum -c smaily-connect-magento2.zip.sha256` in the download folder
before extracting to confirm the archive is the one we built.
[INSTALLING.md](INSTALLING.md) walks through a manual install step by
step — the archive layout, the commands for production and developer
mode, cron, updating to a newer release, and disabling or removing the
module.

### Requirements

- Magento Open Source / Adobe Commerce **2.4.4+** or Mage-OS
- PHP **8.1 – 8.4**
- A working Magento cron. The module runs its jobs in a dedicated
  `smaily_connect` cron group; for near-real-time delivery make sure
  `bin/magento cron:run` executes **every minute** on the server.

### Upgrading from Smaily for Magento 2.8.x

Just update the package and run `bin/magento setup:upgrade` — see
[UPGRADING.md](UPGRADING.md) for exactly what is migrated and what changed.

---

## Finding your way around

Everything lives under **Marketing > Smaily Connect**, four pages:

| Page | What it is |
|---|---|
| **Dashboard** | The landing page: a one-sentence health verdict, connection status for Smaily / Campaign Intelligence / browse tracking, operational counters (deliveries in the last 30 days — only events that reached Smaily or Campaign Intelligence, never a skipped or withdrawn row — the events queued today that are still waiting to send, failures) and the latest queue activity, each row's status labeled and colored as in the Log (*Skipped* and *Withdrawn* included). Every number is a real local queue query. When deliveries failed in the last 24 hours, a warning banner above the verdict counts them and the verdict carries a red **Review failures** button — both open the Log filtered to failed rows; when everything runs, the verdict offers **View full log**. |
| **Initial setup** | The guided five-step onboarding. On a fresh install every Smaily Connect page brings you here until setup is completed; you can re-run it any time — your settings are kept. |
| **Settings** | The initial setup's content as always-available tabs — Connection, Contacts, Automations, Intelligence, RSS. Each tab saves instantly via AJAX. Tabs are deep-linkable (`?tab=rss`). |
| **Log** | One unified delivery log for both Smaily and Campaign Intelligence, with mass retry for failed rows. |

The Settings page (and the initial setup) is the **only** place to configure Smaily
Connect — there is no separate entry under Stores > Configuration. Every
field, including the ones that used to live only there (multilingual mode,
the two Contacts "advanced" toggles), has a home on the module's own
pages.

### Terminology

Smaily Connect uses the same words on every store platform (Magento,
WooCommerce, Shopify) and in both English and Estonian: **Initial setup**,
**Contacts**, **Synchronization settings**, **Automations**, **Overview**.
Three places deliberately keep a different word, because Magento already
owns it:

- **Subscribers only (consent)** stays *subscribers* — it is a lawful-basis
  mode that means precisely Magento's opted-in newsletter subscribers, not
  your whole contact audience.
- *Newsletter subscriber* stays wherever this guide names Magento's own
  subscriber record rather than your Smaily audience.
- There is no **Forms & RSS** section here. Magento ships its own newsletter
  signup block, so this module adds only the **RSS** tab — the product feed
  for the Smaily template editor.

### Multiple websites

If your install has more than one **website** (Stores > All Stores), a
**Website** selector appears next to the Settings tab strip, and the initial
setup opens with a website-picker step before Connect. Each website gets its
own Smaily connection, contact sync, and automation settings — pick a
website from the selector to view or edit its own values; run the initial
setup again for each additional website you want to onboard. Single-website
installs never see the selector or the picker step.

Campaign Intelligence (the recommendation engine tenant) is not yet
per-website — one engine connection currently serves the whole installation
regardless of which website is selected. Each product is sent to it once,
with the price and link of your default website; a product that does not
sell on the default website is sent with the price and link of the first
website it is assigned to. The catalog import sends every product of
every website the same way, with the same price and sale end date as a
product save; a disabled, hidden or out-of-stock product goes as out of
stock.

After installation the admin notifications show "Smaily Connect is ready to
set up", with a link to this guide; after an upgrade from 2.8.x it also says
that the earlier settings were migrated. Finishing the initial setup marks
it as read. After a major version upgrade the module posts a one-time admin
notification suggesting a settings review — nothing is changed or blocked.

---

## Connecting your Smaily account

The fastest path is the guided flow: **Marketing > Smaily Connect >
Initial setup** — five steps (Connect, Contacts, Automations,
Intelligence, Overview), each saved separately, with connection testing and
live workflow lists built in. Each step is headed by its position ("Step 1
of 5") and its title. Completed steps stay unlocked in the step
list (a rail on the left, with a short description under each step name; a
bar across the top on narrow screens), so you
can move back and forward between them freely — also when
revisiting the initial setup after finishing it. A finished setup reopens on
the Connect step as a read-only summary marked *Completed*: the subdomain,
the API username and the connection status. **Edit credentials** opens the
form there (Settings > Connection edits the same values), and **Continue**
or the step list leads through the other steps as before. The Overview step says
that Smaily Connect is syncing only when Smaily accepted the saved
credentials; otherwise it says that syncing starts once Smaily accepts them
and points you to **Settings > Connection**. It ends with **Go to
Dashboard** and **Open Settings**. Everything it writes
lands in the regular configuration, so you can fine-tune it later on the
**Settings > Connection** tab — its own Test connection / Save Connection
footer and connection-status line (Connected / Not connected, with the
account name once connected).

**Connected** means that Smaily accepted the saved credentials the last
time they were checked: when you pressed Test connection, or when you saved
the connection (each save asks Smaily once). Filled-in fields alone are not
enough. The status line shows the answer of each check at once, without a
reload: after Test connection it shows the result for the credentials as
typed, after a save the result for the credentials just saved. Test
connection with empty fields or a refused subdomain asks Smaily nothing and
leaves the status as it was. Changed credentials show *Not connected* until they are checked, and
if Smaily later refuses them — for example because the API user was
removed — the status turns to *Not connected* too. The Dashboard then shows
a **Not connected** verdict with an **Open Connection settings** button;
press Test connection there to check the credentials again.

When the Smaily account's package does not include API access, Smaily
refuses every request before it looks at the credentials (Smaily response
code 227, "A paid package is required"). The store then shows *Not
connected* as well — nothing can reach Smaily — but Test connection, the
connection status, a configuration save, the Dashboard and the Log name
the package as the reason instead of calling the credentials wrong. The
credentials cannot be checked until the account is on a package that
includes the API; press Test connection again after the package is changed.

| Field | Notes |
|---|---|
| Subdomain | Your Smaily subdomain. Pasting the full URL (`https://demo.sendsmaily.net`) also works — it is normalized on save. The subdomain must be a plain one, such as `demo`: letters, digits and hyphens only. Any other value is refused on save and on Test connection, and nothing is sent to Smaily with it. |
| API Username / Password | Create these in Smaily under *Preferences > API*. The password is stored encrypted. |
| Multilingual Mode | See [Multilingual stores](#multilingual-stores). |

Use the **Test connection** button next to the fields for instant feedback
on the credentials as typed — no save needed; a successful test also
refreshes the automation workflow dropdowns. With the password field left
blank, Test connection uses the saved password, as long as the subdomain and
username are filled in. With empty fields and nothing saved to fall back on,
it asks you to fill in the subdomain, username and password. Saving succeeds whenever the
subdomain and username are filled in and the subdomain is a plain one — wrong
credentials do not block it. A password field left empty keeps the saved
password only while the subdomain and username are the saved ones: after you
change either one (the subdomain in another case does not count), enter the
account's password too — until then the save is refused on the password
field, also in a per-language account block. On **Settings > Connection** a save with
credentials Smaily does not accept shows *Saved.* beside the button and
turns the status line to *Not connected*; press Test connection to see
Smaily's reason. In the initial setup the Connect step is saved and the
setup moves on; the Overview step then says that syncing starts once Smaily
accepts the credentials.

When a save fails — in the initial setup or on a Settings tab — an error
banner above the form says why, the status beside the button says *Saving
failed.*, and the field that caused it is marked in red with the message
under it (for example an empty subdomain or username, or a subdomain that is
not a plain one, also in a per-language account block). A Test connection
that fails is shown the same way, with *Connection failed.* beside the
button: the banner gives Smaily's reason or what is missing, and the field
at fault is marked when there is one (an empty field, or a subdomain that is
not a plain one). Editing the marked field removes the mark; the banner goes
with the last mark, or with the next save or test.

Credentials can be set per **website**, or per **store view** when each
language uses its own Smaily account (multilingual mode "Per-language
Smaily accounts" — the Connection panel manages those store-view
credentials for you, see below).

### A separate storefront

When shoppers buy on a separate (headless) storefront and Magento is the
back end only, open **Using a separate storefront?** under the account
fields on **Settings > Connection** and enter the storefront's address as
**Storefront URL**, for example `https://shop.example.com`. The product
links in the catalog sync to Campaign Intelligence and in the RSS feed then
start with that address: the scheme, host and port of Magento's link are
replaced, and the path and the query string stay. Image links, the
abandoned-cart link and Magento's own emails do not change. Leave the field
empty to send Magento's own product links. The value is kept per website and
saved with **Save Connection**; the field is drawn open while a value is
saved.

The field also opens by itself, with a sentence saying why, when the store's
orders of the last 30 days all came through Magento's API: placed through
GraphQL, or through REST without Magento's own storefront session (as a
separate storefront places them). One order through Magento's own checkout
in that time — Luma's checkout included — keeps it collapsed, and so does a
store that has not taken an order since the module was installed.

The address must be https and the host alone: a path other than `/`, a
query or a fragment is refused on save, with the message under the field
(a trailing `/` is dropped). After a save that changes the address, the
result beside the button asks you to run the catalog import again under
**Settings > Intelligence > Historical imports**, so that Campaign
Intelligence gets the new links. The RSS feed shows them within 15 minutes.
The storefront must open Magento's product paths, or redirect them keeping
the query string — see
[HEADLESS_STOREFRONTS.md](HEADLESS_STOREFRONTS.md#product-links-and-images).

Connecting Campaign Intelligence starts the catalog import, so set the
Storefront URL before you connect Campaign Intelligence. The initial setup
has no Storefront URL field: on a new install, finish the initial setup
with its Campaign Intelligence step left unconnected, set the Storefront
URL here, and connect under **Settings > Intelligence**. Connected already
without it: press **Hold back the import** at once, set the Storefront
URL, and start the catalog import then (see
[Connecting](#connecting)).



### Multilingual stores

A store view's language is derived from its locale (`et_EE` → `et`). As
soon as your store views speak more than one language, the Connection
panel (initial setup step 1 and Settings > Connection) opens with a
**routing-mode choice** — four cards; the panels below adapt live to the
selected card, nothing is saved until you press Save/Continue:

| Mode | Meaning |
|---|---|
| Single language | One account, one workflow per trigger (default). |
| Per-language Smaily accounts | Each language has its own Smaily account. The Connection panel shows one credential block per detected language — side by side, each headed by its language — with its own Test connection button and its own status (*Connected* when Smaily accepted that language's saved credentials at the last check, otherwise *Not connected*), plus a **default fallback account** picker. |
| One account, per-language workflows | One Smaily account; each language fires its own workflow. The most common multilingual setup. |
| One workflow branching by language | One workflow; the language split happens inside Smaily. The `language` field is sent with every contact. |

Single-language installations never see the cards — they are locked to
"Single language".

**Per-language workflows.** In the two per-language modes, the
Automations panel (initial setup step 3 / Settings > Automations) grows a
**Per-language workflows** editor: for every automation trigger, one
workflow select per language and a *Default fallback* radio per row. The
workflow lists load live — in "Per-language Smaily accounts" mode each
language row lists that language's own account's workflows. Clearing a
select removes that language's mapping on save.

**How a contact is routed.** An automation fires for the contact's
language row first; if the language has no row, the trigger's *Default
fallback* row handles it; if there is no fallback row either, the default
workflow at the top of the Automations panel is used. A mapped row always
fires **through its own account** — a fallback row belonging to the EN
account posts to the EN account even when the contact came from the ET
store view.

**Default fallback account (per-language accounts mode).** The picked
account handles contacts whose language cannot be matched to any account,
and its credentials also serve every scope without a per-language
override. The connection-status line beside the button — after a save and
after a reload alike, and the Dashboard's Smaily status with it — says
whether Smaily accepted the default fallback account, the account saved
for the whole website, whatever language the default store view has; each
language block's own status shows that language's account. On an
installation with several websites, each website keeps its own default
fallback account and shows its own pick. The default
fallback account is saved for the whole website too: when you pick another
one, enter its password in its block — until then the save is refused on
that block's password field. A fallback account left as it is keeps its
saved password. Switching the multilingual mode away from per-language accounts
removes the per-store-view credential overrides (the panel asks for
confirmation first); workflow mappings are kept but stop being used
outside the per-language modes. The single account is saved for the whole
website, and a password left empty keeps the password of the default
fallback account. So with an empty password the save goes through only for
the fallback account's subdomain and username; the fields can show another
account — the one of the default store view's language — and to keep that
account instead, enter its password. Until then the save is refused on the
password field.

**When a store view's language changes (per-language accounts mode).**
Saving the Connection panel gives every store view the account of its
current language. After you change a store view's locale, open
Settings > Connection, check the block of its new language and save:
until then the store view keeps using the account of its old language.
The block of a language with no saved account of its own shows the
account its store views use now. A password left empty is kept while the
block's subdomain and username are the ones it showed: a store view that
moves to a language takes the password saved for that language's account.
When you enter another subdomain or username in the block — for example
the new language's account in a block that still shows the old language's
— enter its password too; until then the save is refused on the block's
password field.

---

## Contact synchronization

**Settings > Contacts** (or Initial setup step 2)

Contacts sync in near-real-time through a durable queue (no lost events
if Smaily is briefly unreachable — deliveries retry with backoff). When one
store action saves the same contact more than once, a save that changes
nothing about the contact queues no second sync: a registration with the
newsletter box ticked queues one contact sync under **Subscribers only**.

### Contact sync mode (lawful basis)

| Mode | Who is synced | Smaily unsubscribes mirror back? |
|---|---|---|
| **Subscribers only (consent)** — default | Only opted-in newsletter subscribers | **Yes** — a contact who unsubscribes in Smaily is unsubscribed in Magento too (and vice versa) |
| All customers (legitimate interest) | Every registered customer, as a soft opt-in: `is_unsubscribed` is omitted, so a customer new to Smaily becomes a subscriber and a contact Smaily already has keeps its status. A customer who unsubscribes in the store is sent as unsubscribed, and so is every later send for them (a profile save, a guest order from that email) | No |
| Checkout opt-in only | Nobody automatically — only shoppers who tick the checkout newsletter checkbox. A signup through the newsletter form, the admin or the API alone is not synced; an unsubscribe in the store is | No |

With Magento's **Need to Confirm** newsletter option on, a checkout opt-in
waits for its confirmation email and syncs once it is confirmed. Magento
does not record where a pending signup came from, so under checkout opt-in
only every confirmed signup syncs, a confirmed newsletter-form signup
included.

**What "All customers" requires.** This mode is the EU soft opt-in for
existing customers. It lets you send marketing emails only about products
similar to those the customer bought. The customer must have had a clear
way to refuse marketing emails when they bought, and every email needs an
unsubscribe link. You are responsible for the legal basis. The mode card
in Initial setup and on Settings > Contacts says the same.

Additional options:

- **Synchronized Fields** — which optional fields ride along (first/last
  name, prefix, phone, gender, date of birth, customer ID, customer group,
  subscription type). Phone is the customer's default billing telephone;
  phone and gender reach Smaily under the field names `user_phone` and
  `user_gender` — the names Smaily's WooCommerce plugin uses, so a shopper
  syncing from two stores lands in one field. Empty values are omitted so
  existing Smaily values are never wiped.
- **Include guest order emails** — also sync the emails of guest orders
  placed without the checkout newsletter opt-in. It applies only under
  **All customers**. Under **Subscribers only** and **Checkout opt-in only**
  a guest's email reaches Smaily only when the guest ticks the checkout
  newsletter checkbox, whatever this option says.
- **Show Newsletter Checkbox At Checkout** — adds an opt-in checkbox to the
  checkout payment step; ticking it creates a real Magento newsletter
  subscriber (double opt-in is honored if your store requires
  confirmation).
- **Let Smaily Send Opt-In Emails** — suppresses Magento's own confirmation
  success/unsubscribe emails so your Smaily automations own that
  communication. The double-opt-in confirmation *request* email is never
  suppressed.

---

## Automations

**Settings > Automations** (or Initial setup step 3)

Map Smaily automation workflows (the dropdowns load live from your account)
to store events. Only enabled workflows with the **"form submitted"**
trigger are listed — that is the only trigger type the Smaily API can
enroll contacts into; workflows with other triggers (e.g. "subscribed to
list") cannot be fired by an integration and are therefore not offered.
When the list cannot be loaded — on opening the tab or after **Refresh
workflows** — the reason shows beside the tab's **Save Automations** button
(in the initial setup, beside the step's buttons).
The events:

- **Welcome** — fires when a shopper subscribes to the newsletter in your
  store: the newsletter form, the registration form, the newsletter page of
  their account, the checkout opt-in or the double opt-in confirmation link.
  A shopper who unsubscribed and subscribes again gets it again. A
  subscription made in the admin, through the API (REST, SOAP or GraphQL)
  or by an import still syncs the contact to Smaily, but sends no welcome.
  Under **Checkout opt-in only** a newsletter-form signup syncs nothing, so
  it sends no welcome either.
- **First Order** — fires on a customer's first order, with
  `order_id`, `order_total`, `order_currency`, `is_first_order` fields for
  template personalization.
- **Abandoned Cart** — see below.

An automation never re-subscribes a contact who unsubscribed in Smaily, in
any contact sync mode: the extension always sends `force_opt_in=false`. An
automation reaches only a contact Smaily already has: for an address Smaily
does not have, the trigger creates no contact and sends nothing.

### Segmenting on when an automation last ran

Every store event also writes its own contact field recording **when that
automation last ran** for the contact:

| Event | Contact field |
|---|---|
| Welcome | `welcome_automation_at` |
| First Order | `first_order_automation_at` |
| Abandoned Cart | `abandoned_cart_automation_at` |

The value is the moment the event fired, as `YYYY-MM-DD HH:MM:SS` in **UTC**,
rewritten on every run — so it always holds the most recent one. Use it in
Smaily segments for rules like "has received the welcome letter" or "got the
abandoned-cart reminder more than 30 days ago". An event that has not fired
for a contact writes nothing at all, leaving any value Smaily already holds
untouched. The same field names are used by Smaily's WooCommerce and Shopify
plugins, so a segment built on them transfers between stores.

### Stopping the abandoned-cart follow-ups once the shopper buys

When a shopper you sent an abandoned-cart reminder to completes an order, the
extension writes one more field onto their contact:

| Event | Contact field |
|---|---|
| Abandoned cart purchased | `abandoned_cart_purchased_at` |

The value is the date and time of the purchase, in UTC, in the same format as
the fields above. Use it as the **exit condition** on every follow-up step of
your abandoned-cart workflow: continue only while `abandoned_cart_purchased_at`
is **earlier than** `abandoned_cart_automation_at` (or empty) — the shopper has
bought when the purchase time is the later of the two. Guests and signed-in
shoppers alike are covered, and the purchase counts as soon as the order is
placed, without waiting for payment status.

It is written **only** for a shopper the extension actually tracked as having
abandoned a cart and whose reminder went out to Smaily — an ordinary purchase
writes nothing and creates no contact. The reminder's cart and product fields
are left exactly as the reminder wrote them. If the shopper buys before the
reminder has gone out, the reminder is dropped instead and nothing is written:
the Log row is closed without being sent, its response reading `cancelled`. A
reminder that failed for good means nothing is written either.

The field is written only to a contact Smaily already has, so it never
creates a contact. Before sending it, the extension looks the address up in
Smaily; when Smaily does not have it, the Log row is closed without being
sent, labeled *Skipped*, and says why. A shopper who unsubscribed in the store is sent as
unsubscribed with this field.

## Abandoned cart

A cart counts as abandoned when it has items and an email address and has
been idle past the **cutoff** (default 30 minutes, minimum 10). The email can
come from a signed-in customer, an order in progress, or the address a guest
types at checkout. Carts older than 24 hours are never mailed — a recovering
cron never blasts stale reminders. Each cart is mailed **once**, and one
email address gets at most **one reminder in 24 hours**, whatever number of
carts carry it (compared in any case): a further cart of that address within
24 hours of a reminder is not mailed, now or later, and its row in the
[Log](#the-log-and-troubleshooting) is *Skipped* and says why.

**When a guest's cart gets its email.** Magento's own checkout keeps a
guest's email in the browser until the payment step. While the abandoned-cart
automation is on, Smaily Connect saves it to the cart earlier: as soon as the
email field holds a valid address and the guest stops typing for two seconds
(the moment Magento checks whether the address has an account). A guest who
types their email and leaves on the shipping step, before clicking **Next**,
therefore has a cart that can be reminded. This applies to Magento's own
checkout on Luma-based themes and to Hyvä's Luma-based checkout. On Mageplaza
One Step Checkout the address reaches the cart as that checkout saves it (see
[Third-party one-step checkouts](#third-party-one-step-checkouts)). On other
checkouts, Hyvä Checkout included, a guest's cart gets its email when that
checkout saves it to the cart — on Magento's flow, at the payment step.

A guest who changes the address changes the cart's address too. To keep the
checkout from being used to put other people's addresses on carts, three
limits apply: one IP address can save an email at most 30 times in 10
minutes (an IPv6 address counts together with the rest of its /64 block), one
cart takes at most five different addresses, and the whole installation saves
at most 2,000 addresses an hour. Past a limit, the cart keeps the address it
has until the guest submits the payment step.

**Behind a reverse proxy, Varnish or a load balancer**, set Magento up to see
each shopper's own IP address. Either the web server puts the shopper's
address in place of the proxy's before Magento runs (nginx `real_ip`, Apache
`mod_remoteip`), or a `di.xml` tells Magento which forwarding header to read:
the `alternativeHeaders` argument of
`Magento\Framework\HTTP\PhpEnvironment\RemoteAddress` (for example
`HTTP_X_FORWARDED_FOR`), with `trustedProxies` listing the proxy's own
addresses. Otherwise every shopper reaches Magento from the proxy's address:
the limit of 30 in 10 minutes is then one limit for the whole store, and once
it is used up no guest's email is saved to a cart until the next 10 minutes
begin. Smaily Connect's browse tracking limit counts by the same address.

Whether the reminder reaches the guest does not depend on when the email was
saved: as for every automation, it reaches only a contact Smaily already has
and never re-subscribes one who unsubscribed (see [Automations](#automations)).

That covers only the reminders Smaily Connect sends. Before you switch the
abandoned-cart automation on, list which modules in your store send
abandoned-cart emails today, and switch those emails off there — for example
a Magento extension such as Mageplaza SMTP or Avada Email Marketing, or Adobe
Commerce's own email reminder rules. Otherwise a shopper gets two reminders
for one cart. The setting's note on **Settings > Automations** (and Initial
setup step 3) says the same.

The automation receives up to 10 products as numbered fields
(`product_name_1`, `product_sku_1`, `product_quantity_1`, `product_price_1`
(incl. tax), `product_base_price_1`, `product_description_1`,
`product_image_url_1`, …) and `over_10_products`, which is `true` when the
cart holds more than 10 products and empty otherwise. There is nothing to
configure: every product field is always sent, and all ten slots and
`over_10_products` are sent on every reminder — the slots the cart does not
use are sent empty, and so is `over_10_products` for a cart of 10 products or
fewer, which is what clears a previous, larger cart from the contact. Your
Smaily template decides which of them to show.

`{{abandoned_cart_url}}` is a secure recovery link that restores the exact
cart when clicked (a signed link; carts belonging to a registered customer
ask them to sign in first). The link expires 30 days after the reminder is
created: an expired link opens the cart page with the notice "This cart link
has expired." and restores nothing.

## Third-party one-step checkouts

Some stores replace Magento's checkout with a one-step checkout extension.
This section covers **Mageplaza One Step Checkout** on a Luma-based theme.
What it says was read from the extension's source code (versions 2.8.2 and
4.0.10) and its public documentation; it was not tested on a running store.
Newer versions can differ, so do the checks below on your store after you
install or update either extension. Other one-step checkouts and Mageplaza's
Hyvä edition are not covered.

| What | Result | How sure |
|---|---|---|
| Smaily Connect's newsletter checkbox shows | Yes, below the payment methods. One Step Checkout builds its page from Magento's checkout layout and keeps the area below the payment methods where Smaily Connect adds the checkbox. | Read from source — check on your store |
| Ticking it subscribes the shopper | Yes. The checkbox saves the choice for the cart when it is ticked, and placing the order reads it, as in Magento's checkout. | Read from source — check on your store |
| A guest's email reaches abandoned-cart reminders | Yes. When a guest types a valid email address, One Step Checkout saves it to the cart at once (while it checks whether the address has an account). The abandoned-cart scan reads it from the cart. | Read from source — check on your store |
| One Step Checkout's own newsletter checkbox | It subscribes the shopper to Magento's newsletter when the order is placed. What Smaily Connect then does depends on the contact sync mode — see below. | Read from source |

**Show one newsletter checkbox, not two.** Both checkboxes are on after
install: Smaily Connect's **Show Newsletter Checkbox At Checkout** and One
Step Checkout's **Show Newsletter Checkbox** both default to *Yes*. A
shopper who ticks both is subscribed once, but with Magento's **Need to
Confirm** option on, Magento sends the confirmation request twice.

We recommend Smaily Connect's checkbox. In One Step Checkout's
configuration, set **Show Newsletter Checkbox** to *No*. Smaily Connect's
checkbox works in every contact sync mode, starts the Welcome automation
and is never ticked in advance.

If you keep One Step Checkout's checkbox instead, set Smaily Connect's
**Show Newsletter Checkbox At Checkout** to *No*, and set One Step
Checkout's **Checked Newsletter by default** to *No* — a box that is ticked
in advance is not consent. Then:

- Under **Subscribers only** and **All customers**, the new subscriber
  syncs to Smaily. No Welcome automation starts: One Step Checkout
  subscribes the shopper while the order is placed through Magento's API,
  and Welcome fires only for subscriptions made on the storefront (see
  [Automations](#automations)).
- Under **Checkout opt-in only**, the subscriber does not sync, because
  only Smaily Connect's checkbox counts as the checkout opt-in.
- With **Need to Confirm** on, the subscriber syncs in every mode when the
  shopper clicks the confirmation link, and Welcome starts then.

**Check on your store after install:**

1. Open the checkout as a guest. The page shows one newsletter checkbox,
   below the payment methods if it is Smaily Connect's.
2. With the abandoned-cart automation on, add a product to the cart, open
   the checkout as a guest, type an email address and leave the page.
   After the cutoff, the reminder appears in **Marketing > Smaily Connect >
   Log**.
3. Place a guest order with the checkbox ticked. The shopper appears in
   **Marketing > Communications > Newsletter Subscribers** and, in the Log,
   as a contact sync to Smaily.

If Smaily Connect's checkbox does not show, keep One Step Checkout's
checkbox as described above.

## Product RSS feed

A product feed for the Smaily template editor's RSS block:

```
https://your-store.example/smaily/rss/feed
```

Optional query parameters:

| Param | Values | Default |
|---|---|---|
| `category` | Category **ID** | all products |
| `limit` | 1–250 | 50 |
| `sort` | `created_at`, `updated_at`, `name`, `price` | `created_at` |
| `order` | `asc`, `desc` | `desc` |

You do not need to build the URL by hand: the **Feed URL Builder** on the
**Settings > RSS** tab assembles it live as you pick the category, limit and
sorting, with a one-click **Copy** button. The initial setup's Overview step links
straight to it.

Items include `smly:price` / `smly:old_price` / `smly:discount` (prices as
shown in your storefront, tax included). Only catalog-visible, enabled
products are listed; configurable variants resolve to their parent. Item
links start with the **Storefront URL** when one is set (see
[A separate storefront](#a-separate-storefront)). The feed
is a single store-wide on/off toggle on the **Settings > RSS** tab, saved with
that tab's own **Save** button. Responses
are cached for 15 minutes.

---

## Campaign Intelligence

The optional Smaily recommendation engine: your catalog, customers, orders
and (opt-in) browsing behavior power personalized recommendations and
engine-run automations (replenishment reminders, win-back, …).

Campaign Intelligence is an **optional paid add-on** (€250/month), added to
your regular Smaily monthly payment. Contact Smaily to activate it, or set it
up later. Initial setup step 4 always opens with this introduction;
**Settings > Intelligence** shows it only until Campaign Intelligence is
connected.

### Connecting

1. Get a one-time **setup URL/token** from Smaily.
2. Paste it on **Settings > Intelligence** (or Initial setup step 4) and save.
   The token is exchanged immediately and never stored; the status row shows
   the connected tenant.
3. Connecting starts the **Catalog** import, which sends your whole catalog
   once; after that Campaign Intelligence gets only the changes. A notice
   under the status row says "The catalog import has started". The import
   waits for the next cron run (usually within a minute): press **Hold back
   the import** before then and nothing is sent. Pressed once the import
   has begun, it stops the import after the batch of products (up to 100)
   it is queuing: the products already queued for sending still reach
   Campaign Intelligence, the rest are not sent, and the notice says how
   many were queued. Either way
   you can start the import later under **Settings > Intelligence >
   Historical imports** (see [Historical import](#historical-import-backfill)).
   A catalog import that is already queued or running when you connect
   again is left as it is — connecting does not start a second one. The
   **Customers** and **Orders** imports are not started by connecting:
   start them there when Campaign Intelligence should get your history.
   A connection made from the command line (`bin/magento config:set
   smaily_connect/intelligence/setup_token <setup URL>`) starts the
   catalog import as well, but the command prints no notice:
   `bin/magento smaily:backfill:status` lists the import, and
   **Cancel import** on the **Catalog** card under **Settings >
   Intelligence > Historical imports** holds it back.

While no Storefront URL is saved for the website, the setup URL field
says that a store with a separate storefront sets its Storefront URL
before connecting — or connects and presses **Hold back the import** (see
[A separate storefront](#a-separate-storefront)). Initial setup step 4
says to finish the setup without connecting, enter the address under
**Settings > Connection > Using a separate storefront? > Storefront URL**,
then connect under **Settings > Intelligence**; **Settings > Intelligence**
says to set it under **Settings > Connection > Using a separate
storefront?** first. With a Storefront URL saved, and once connected,
neither says it. Saving a Storefront URL on **Settings > Connection** hides
the note on **Settings > Intelligence** at once, and clearing it and saving
shows the note again; no reload is needed.

The setup URL must be an https address on `intelligence.smaily.com`; a bare
token is exchanged there too. Any other address is refused before anything is
sent. The connection is saved only when every address the engine answers
with is an https address on `intelligence.smaily.com` as well — otherwise the
admin says so and the store is not connected. A connection saved earlier
keeps working as it is.

### What syncs

Connecting the engine syncs everything below — there is no per-entity on/off
toggle; catalog, customer and order sync run automatically once connected.

| Data | When |
|---|---|
| Catalog | On product save/delete (deletes become out-of-stock) and on every stock change — a shipment that sells the last unit, a credit memo that puts it back, an Advanced Inventory or Sources edit, an API stock update. Products deleted with Magento's import (**Delete** behaviour) are removed too. The whole catalog goes once, with the catalog import; there is no periodic full re-sync. A change made outside Magento's own product save — an ERP link, a CSV or `bin/magento import` run, a direct database import — is not seen: start the catalog import by hand afterwards |
| Customers | On profile create/update (no consent fields — the engine is a separate lawful surface) |
| Orders | On order placement, status changes, and refunds — a credit memo re-syncs the order, so a fully credited line is reported as returned and stops being recommended back to that customer (a partly credited line still counts as kept) |
| Browse events | Product views, searches, cart adds, checkout — batched from the storefront (**Enable storefront browse tracking (product views, searches, cart activity)**, off by default — a separate, consent-gated toggle, not part of the always-on sync above) |

Browse tracking sends events only for a visitor who gave marketing consent
(see [Connecting your cookie consent tool](#connecting-your-cookie-consent-tool))
and sends them through your own server (`smaily/relay`) so the API key never
reaches the browser. On **Settings > Intelligence** the toggle is saved with the tab's
**Save** button, which appears once Campaign Intelligence is connected —
before that the tab has nothing to save, and **Connect** is its only action.

### Connecting your cookie consent tool

The browse tracker needs to know whether the visitor allowed **marketing**
cookies. It asks, in this order, as the WooCommerce plugin does:

1. **Your own consent function**, when the page defines
   `window.smailyConnect.consentOverride`. Its answer decides: `true` means
   consent, any other answer means no consent.
2. **Magento's cookie notice**, when Magento's cookie restriction mode is on
   (**Stores > Configuration > General > Web > Default Cookie Settings >
   Cookie Restriction Mode**): consent once the visitor allows cookies
   there. This consent counts only on the website where the visitor gave
   it, and on Magento's standard theme the notice is not shown again on a
   second website that shares the cookie domain, so a visitor who allowed
   cookies on another website is not tracked there until they consent
   through your own consent tool (1.); on Hyvä the notice shows on each
   website.
3. **Otherwise there is no consent.**

Without consent the tracker sends no browse event and sets no session
cookie (`smaily_anon_sid`). Campaign-click capture is not affected: the
cookies from a Smaily email link (see
[Recommendation attribution](#recommendation-attribution)) are written
without consent, as in the WooCommerce plugin, so a purchase is still
credited to the email.

So a store with browse tracking on and neither of the first two collects
no browse events. The admin says so: the note under the browse-tracking
toggle (Settings > Intelligence and Initial setup step 4) links to both
ways below, and an admin notification says the same when browse tracking
is on and cookie restriction mode is off in any store view. A store that
already uses its own consent function can mark that notification read.
Connect one of the two:

- **Magento's cookie notice** — switch on Cookie Restriction Mode. Nothing
  else is needed.
- **Your own consent tool** (Amasty, Cookiebot and others leave Magento's
  mode off) — add a small script that answers for the tool, below.

**The contract.** Define the function before the page finishes loading —
for example in **Content > Design > Configuration > (store view) > HTML
Head > Scripts and Style Sheets**:

- `window.smailyConnect.consentOverride()` returns `true` only when the
  visitor allowed marketing cookies in your tool. The tracker calls it when
  the page loads, again before each send, and on each event below.
- When the visitor changes their choice on the page, fire
  `document.dispatchEvent(new CustomEvent('smaily:consent-changed'))`. The
  tracker asks again: with consent it starts tracking at once (the page's
  own view included); without it, nothing more is sent from that page.
  Without the event, tracking starts on the visitor's next page view.
- Magento's own cookie notice needs no event: the tracker already listens
  to it (`user:allowed:save:cookie`; on Hyvä, `user-allowed-save-cookie`).

The module contains no code for any consent tool. The two examples below
are for your own theme or the HTML Head field; check them against the tool
version you run.

**Example: Amasty Cookie Consent.** Amasty keeps the visitor's choice in the
cookie `amcookie_allowed`, a comma-separated list of the cookie group ids
the visitor allowed. Group ids differ per store: look up the id of your
marketing group in Amasty's cookie group settings and put it in the script.

```html
<script>
window.smailyConnect = window.smailyConnect || {};
window.smailyConnect.consentOverride = function () {
    var MARKETING_GROUP_ID = '3'; // your marketing group's id in Amasty
    var match = document.cookie.match(/(?:^|; )amcookie_allowed=([^;]*)/);

    return !!match && decodeURIComponent(match[1]).split(',').indexOf(MARKETING_GROUP_ID) !== -1;
};
</script>
```

**Example: Cookiebot.** Cookiebot exposes the choice as
`Cookiebot.consent.marketing` and fires `CookiebotOnConsentReady` and
`CookiebotOnAccept` on `window`.

```html
<script>
window.smailyConnect = window.smailyConnect || {};
window.smailyConnect.consentOverride = function () {
    return !!(window.Cookiebot && window.Cookiebot.consent && window.Cookiebot.consent.marketing);
};
['CookiebotOnConsentReady', 'CookiebotOnAccept'].forEach(function (name) {
    window.addEventListener(name, function () {
        document.dispatchEvent(new CustomEvent('smaily:consent-changed'));
    });
});
</script>
```

### Recommendation attribution

Campaign clicks (`smaily_rec`/`smaily_vt`/`smaily_ctx` URL parameters) are
captured into first-party cookies and stamped onto the resulting order, so
the engine can credit purchases to recommendations. This works with Full
Page Cache because the capture runs client-side.

A recommendation id must be a well-formed UUID. Campaign Intelligence
refuses a whole order over a malformed one, so the extension ignores a
malformed id — from a truncated link or a test-email placeholder — in the
link and again when it sends the order. The order still reaches Campaign
Intelligence, without that click.

The visitor token (`vt_` followed by letters and digits), the context and
the anonymous session id (letters, digits, `.`, `_` and `-`) are checked the
same way, each up to 64 characters. A value that does not fit is ignored on
its own, and the order keeps every other attribution value.

### If your Campaign Intelligence account is deactivated

If Smaily deactivates the Campaign Intelligence account behind this store —
a suspension, or an account that has been closed — the engine stops
accepting data and says so. The extension remembers that answer instead of
retrying forever:

- Nothing more is sent — no catalog, customer or order data, no browse
  events, no historical imports.
- Nothing is lost. Everything already queued stays queued, untouched, and
  goes out in order once the account is active again. **Details** on such
  a row in the **Log** says it waits for the Campaign Intelligence account
  to be active again.
- A shopper's personalization choice made meanwhile — an opt-out of
  personalized recommendations, or opting back in, for example by
  unsubscribing from or subscribing again to your newsletter — waits in the
  queue too and reaches Campaign Intelligence once the account is active
  again. When the shopper changed their mind meanwhile, the engine gets
  the newest choice: an older choice that differs from it is not sent and
  reads Skipped in the **Log**.
- **Settings > Intelligence** says the account is not active, links to your
  Smaily account and offers **Check again**. The engine-bound historical
  imports are unavailable meanwhile; the contact import on the
  **Contacts** tab is unaffected, as is all Smaily email sending.
- The Dashboard verdict names the deactivated account rather than
  reporting an outage, and an admin notification says the same. Waiting
  does not fix it — only Smaily can make the account active again.
- **Settings > Automations** shows the same explanation in place of the
  Campaign Intelligence automations, which cannot be read or saved while
  the account is not active; your regular Smaily automations above them
  are unaffected.
- `bin/magento smaily:gdpr export|erase` still exports or erases the
  store's own data for the address, and says that the Campaign
  Intelligence data was not exported or erased because the account is not
  active: ask Smaily to make the account active again, then run the
  command again.

Once Smaily tells you the account is active, press **Check again**. The
health check asks the engine again on its own every 15 minutes, so sending
also resumes without you pressing anything.

### Engine automations

The engine-run triggers live right under your regular automations on
**Settings > Automations**, which lists the triggers available to your
sector. Each row maps a trigger to a
Smaily workflow with a cooldown, an optional daily cap and a **test mode**
(on by default — fires reach only the listed test emails). Nothing is
enabled without your explicit action.

Going live: turning **Test mode** off and saving asks for real sends, but
does not switch them on. Smaily switches real sends on after you confirm;
until then the trigger stays in test mode, and each card says so. After a
save, every card shows the state Campaign Intelligence stored — **Off**,
**Test mode** or **Active** — so a trigger whose real sends are not on yet
shows **Test mode** with its box ticked again. **Active** means real
customers receive the emails. An **Active** trigger saved again with
**Test mode** off stays active. Ticking **Test mode** or unticking
**Enabled** takes effect at once; real sends then need Smaily again.

---

## Historical import (backfill)

Historical imports live on the **Settings** page (or the CLI) and run in
the background, a chunk per cron minute, without ever blocking live
traffic:

- **Contacts → Smaily** — Settings > **Contacts** tab (per website).
  The import sends the contacts the website's
  [contact sync mode](#contact-sync-mode-lawful-basis) covers:
  - **Subscribers only** — the newsletter subscribers, subscribed and
    unsubscribed.
  - **All customers** — those subscribers, then every other registered
    customer of the website.
  - **Checkout opt-in only** — nobody: a contact reaches Smaily only when
    a shopper ticks the checkout checkbox, so the import finishes at 0.

  A newsletter subscriber goes with its subscription status in the store,
  subscribed or unsubscribed, and a contact Smaily already has takes that
  status. Under **Subscribers only** nobody becomes subscribed by being
  imported: a signup still waiting for its confirmation email is not
  imported, and it syncs as subscribed once it is confirmed. Under **All
  customers** (the soft opt-in) every other customer goes without a
  status, as the live sync sends them: a customer new to Smaily becomes a
  subscriber, and a contact Smaily already has keeps its status. This
  includes a customer whose newsletter signup still waits for its
  confirmation email. A customer who unsubscribed in the store goes as
  unsubscribed. Guest-order emails are not imported. The estimate above the **Start import** button counts these
  contacts for the mode picked on the panel.
  When Smaily answers a chunk with "invalid data", the import sends that
  chunk's contacts again one at a time, so only the contacts Smaily
  refuses count as failed and the others are imported.
  The import obeys that website's **Sync contacts to Smaily**
  switch exactly like the live syncs do: with the switch off the import
  button is disabled and says so, and an import started any other way
  (the CLI, or one already queued when you switched it off) sends nothing
  and finishes at 0. An import that was already running when you switched
  it off stops at its next chunk and is reported as canceled, keeping the
  count it had genuinely sent.
- **Catalog / Customers / Orders → Campaign Intelligence** — Settings >
  **Intelligence** tab, one card per import.
  Campaign Intelligence gets the whole catalog once, from the **Catalog**
  import: connecting starts it (see [Connecting](#connecting) — it can be
  held back right after connecting).
  After that, product saves, stock changes and deletions reach Campaign
  Intelligence on their own within a minute or two; nothing re-sends the
  whole catalog periodically. If your store changes products outside
  Magento's own product save — an ERP or PIM link, a CSV or
  `bin/magento import` run, a direct database import — start the
  **Catalog** import by hand after such a change, so Campaign
  Intelligence gets the new prices, stock and products. The import does
  not remove products: Campaign Intelligence learns of a deletion only
  from Magento — its own product delete (in the admin or through the
  API) and its import with the **Delete** behaviour (**System > Data
  Transfer > Import**, or a tool that runs Magento's import). A product
  deleted any other way — straight in the database, or by a tool that
  bypasses Magento's import — stays in Campaign Intelligence as it was, and a
  catalog import does not change that. To take such a product out of the
  recommendations, add a product with the same SKU in the admin with
  **Enable Product** off and save it: Campaign Intelligence then has it
  as out of stock. You can delete that product in the admin afterwards
  as usual. The extension has no other way to remove a product from
  Campaign Intelligence. When a release note asks you to
  start the catalog import (for example because Campaign Intelligence now
  gets a new product detail), start it under **Settings > Intelligence**.
  The **Catalog**, **Customers** and **Orders** imports need a Campaign
  Intelligence connection. The import cards show only while Campaign
  Intelligence is connected; a **Start import** pressed on a page opened
  before it was disconnected does not start the import and says why
  beside the button — "Campaign Intelligence is not connected, so there
  is nowhere to send the catalog." (for **Customers**: "…the customer
  data.", for **Orders**: "…the order data.") — and
  `smaily:backfill:start catalog|customers|orders` stops with the same
  message. The **Contacts** import goes to Smaily and does not need
  Campaign Intelligence.

Each import is a card. Before its first run it offers **Start import**.
Once started, a status pill in the card's header says where it is —
*Pending* (queued for the next cron run), *Running*, *Done*, *Stopped* or
*Canceled* — and a progress bar shows how far it got, with "X of Y" and the
percentage under it. While a job is queued or running the card offers only
**Cancel import** — the background worker stops cleanly at its next page
boundary. After it ends, **Run again** starts a fresh import. A canceled import is
terminal: starting the same import again begins a fresh run from the
beginning. (An import interrupted by an error, on the other hand, resumes
from its last cursor.) Imports are safe to re-run either way: deliveries
are deduplicated on the receiving side.

After an import finishes, its outcome and timestamp stay visible on the
card — you do not have to keep the page open:

- **"Done, X of Y synced"** — everything landed.
- **"Done, X of Y synced — N failed"** — individual items failed
  permanently, with a link to the Log pre-filtered to those rows. Items
  still waiting for an automatic retry are *not* counted as failed.
- **"Stopped before an error"** — the job itself hit an error and stopped
  at a page boundary; nothing was lost, press **Run again** to run it
  again.
- **"Canceled"** — stopped on your request; starting again begins a
  fresh import.

## The log and troubleshooting

- **Marketing > Smaily Connect > Log** — every delivery in one grid:
  Smaily (contact syncs, automation triggers) and Campaign Intelligence
  (catalog, customers, orders, browse events), told apart by the
  **Source** column, with status (a colored pill: amber while a row waits
  or is being sent, green when delivered, red when failed, gray when
  withdrawn or skipped), attempts and the last error. The **Entity** of a
  contact sync or an automation row is the contact's email address, and
  the Entity filter finds the contact's rows by it. An address longer than
  64 characters is stored as a keyed hash of the address instead, so it is
  never cut: the **Log** and the Dashboard show its first 12 characters
  (the Entity filter finds the rows by them), and **Details** shows the
  address in the payload. A row queued by an earlier release candidate
  shows such an address cut after 64 characters. A stock change
  (a shipment, a credit memo, a Sources or Advanced Inventory edit) first
  shows as a waiting *catalog_changed* row that names the product; within
  a minute it is replaced by the product's *catalog* row, one per product
  however many stock changes it had in that minute. The error
  column shows Smaily's or Campaign Intelligence's own words, not our
  internal name for the failure — except when Smaily rejected the API
  credentials: then it says "Smaily API credentials were rejected", which
  tells you what to fix. One more Smaily answer is worded by the
  extension too: a refusal because the account's package does not include
  API access. Any other HTTP error from Smaily shows its status before
  Smaily's answer ("Smaily API request failed with HTTP 404: Not Found");
  a long answer is cut after 500 characters. Details shows what Smaily
  actually answered, in full, under the last API response. A
  Smaily delivery error reads in your admin language,
  whatever the language of the store that sent the row; the log file
  records it in English. The column's filter finds a row by the words the
  column shows, in your admin language; it does not find our internal
  failure class, nor a password or key that the column hides. Select failed rows and **Retry** — each row is routed
  back to its own queue; rows that cannot safely be sent again are left
  alone and counted ("2 event(s) queued for retry, 1 skipped because
  sending again would not be safe").
- **Send again** on a failed row queues a fresh attempt. The failed row
  stays exactly as it is — it is your record of what went wrong — and the
  new row notes which row it repeats, who pressed the button and when;
  **Details** on the new row shows that line.
- **Some failed rows have no Send again button**, because sending again
  would reach the shopper twice or reach nobody. Details on such a row says
  which of the three it is: the reminder was withdrawn when the shopper
  completed the purchase, a later message of the same kind already reached
  that contact, or the contact's data was erased under Art. 17. A later
  row that was skipped or withdrawn reached nobody, so it does not count as
  "already reached".
- **Withdrawn** is its own status in the grid and in the status filter: a
  reminder the store called back because the shopper bought in the
  meantime. Nothing was delivered and nothing failed, so it is labeled as
  neither.
- **Skipped** is its own status too: a row the store closed without
  sending anything, because sending could not have done what it was for:
  the abandoned-cart purchase marker for an address Smaily does not have,
  an abandoned-cart reminder for an address that got one for another cart
  in the last 24 hours, an automation trigger with no Smaily workflow mapped
  to it, a
  personalization choice the shopper has changed since (the newer choice
  is sent on its own row), or linking the browsing of a shopper who opted
  out of personalized recommendations to their address. Details shows the
  reason in your admin language, and the row does not retry. A skipped row
  is not counted as delivered on the Dashboard.
- **Details** on any row opens a narrow panel on the right with the full
  picture. Its header names the event (its id and type) and shows its
  status. The attempt history lists what happened in order: when the row
  was queued, each attempt and its outcome, and the next attempt when one is
  scheduled. The row stores only its latest attempt, so earlier attempts are
  listed without a time or an error, and the panel says so. Below it: the
  payload exactly as it was (or will be) sent, the attempt count, when the
  next automatic retry happens (or an honest "this row will not retry on
  its own", or, while the Campaign Intelligence account is deactivated,
  that a row bound for it waits for the account), the last error — with our internal failure class beside it —
  and the last API response: the HTTP status and what Smaily or Campaign
  Intelligence answered. A row that went out with others in one request
  shows only its own part of it. A retry keeps the evidence of the attempt
  before it: when a later attempt gets no answer at all (a network
  failure), the last response that did arrive stays. A row that never
  reached the server — skipped because no workflow is mapped, withdrawn,
  or stopped before any request — says that nothing was sent for it.
  Passwords and API keys are never shown; contact data — email addresses
  included — is shown in full, as in the WooCommerce plugin's log, so you
  can debug a delivery. Treat what you read and copy here as personal
  data. At the bottom, **Send again** appears on exactly the rows where the
  grid offers it (it asks first, like the grid's button), and **Copy
  payload** copies the payload as the panel shows it, with its secrets
  hidden. Escape or the close button closes the panel.
- When deliveries failed in the last 24 hours, a banner above the grid
  says so and links straight to the grid pre-filtered to failed rows; the
  dashboard's failed-deliveries tile links to the same view.
- Deliveries retry automatically with backoff (1 min, 5 min, 15 min,
  then 1 h: 5 attempts over about 81 minutes) before parking as *failed*
  for manual retry. A delivery that was refused
  outright — wrong credentials, a deleted workflow, a rejected address,
  data Smaily answers is invalid, a link of browsing to a customer that
  Campaign Intelligence refused — is
  not retried at all: it is marked *failed* immediately, with the
  refusal in the last error, so the failed count tells you now instead of
  about 81 minutes later. So is a row the extension itself can never send (its
  data is incomplete, or no part of the extension handles it); Details
  says it stopped after 1 of 5 attempts. Smaily answers a group of contact syncs as
  a whole, so when it answers "invalid data" for a group, the extension
  sends that group's contacts again one at a time in the same run: the
  other contacts sync, and only the contact Smaily refused fails, with
  Smaily's answer. When Smaily asks the store to slow down, the row waits
  exactly as long as it asked before the next attempt.
- An admin notification appears when the engine has been unreachable for
  over an hour, or when many events failed within 24 hours.
- Logs: `var/log/smaily_connect.log` on the server. By default the
  extension writes errors only. There is no control for this in the admin:
  a developer with shell access raises the level for troubleshooting and
  lowers it afterwards from the Magento root:

  ```
  bin/magento config:set smaily_connect/logging/verbosity debug
  bin/magento config:set smaily_connect/logging/verbosity error
  ```

  `info` is the middle level (batch outcomes, retries); `debug` adds every
  Smaily API request and its response code. The command takes effect at
  once — it clears Magento's configuration cache itself. **The detailed
  levels write more about your contacts to a file on the server**: request
  payloads are summarized, a network failure is logged with the address it
  called but without its query string or the contact's address, and every
  email address written to the file is masked to its first characters
  (`j***@e***.com`) — also one that is URL-encoded or JSON-escaped, or
  quoted in a message Smaily or Campaign Intelligence sends back. Treat the
  file as personal data all the same, switch the level back to `error` when
  you are done, and delete the lines you no longer need.
- Sent queue rows are pruned after 30 days, failed rows after 90. The same
  nightly job also tidies the abandoned-cart tracker — the small table that
  remembers which carts the extension has already dealt with: a finished
  record (reminded, skipped, purchased, expired, erased) is dropped 30 days on, and
  so is any record whose cart Magento has already deleted. A cart that is
  still in the store and still being watched is never touched.

## Privacy and GDPR

- **Marketing consent** lives on the Magento newsletter subscription and
  the Smaily contact (`is_unsubscribed`) — bidirectional in consent mode.
- **Personalization (profiling) consent** is a separate axis: customers can
  opt out of personalized recommendations under **My Account >
  Personalization**. The page and its menu link exist only while Campaign
  Intelligence is connected and the account is active; on a store without
  it the page is not found, because nothing personalizes recommendations
  there. The page shows the shopper's choice only when the store knows it —
  from its own record of an opt-out, or from a successful read of the Smaily
  contact. When Smaily cannot be read and the store holds no opt-out, the
  page says the preference could not be loaded and offers a single **Opt
  out of personalized recommendations** button instead of a tick box, so
  the shopper can still opt out. The choice is stored on the Smaily contact
  when Smaily already has one — recording it never creates a contact or
  subscribes anyone — kept by the store itself, and enforced by the engine. It reaches Campaign Intelligence
  as a queued delivery (type `engine.profiling_consent` in the **Log**), so
  an engine outage only delays it: it is retried like every other delivery.
  Such a row does not keep the shopper's address beside the data it sends:
  its Entity is a keyed hash of the address, which the **Log** and the
  Dashboard show by its first 12 characters (the Entity filter finds the
  row by them); **Details** shows the address in the payload. A row queued
  by an earlier release candidate shows the address as its Entity.
  While the Campaign Intelligence account is deactivated, it waits and is
  sent once the account is active again. A choice Campaign Intelligence
  refuses as invalid cannot succeed by sending it again, so it fails at
  once in the **Log** with the engine's answer.
  A delivery that waits while the shopper changes their mind is not sent —
  only the newest choice reaches the engine. Campaign Intelligence keeps an
  opt-out only for a shopper it already knows: an opt-out made before that
  (for example by a guest who only subscribed to the newsletter) closes as
  delivered in the **Log**, with nothing to exclude yet. The store keeps
  the opt-out, and when Campaign Intelligence later confirms a customer or
  an order of that shopper — from an order, a customer account save, or the
  customer or order import — the opt-out is sent again as a new
  `engine.profiling_consent` row, so the shopper is not personalized once
  Campaign Intelligence knows them. A shopper who opted back in meanwhile,
  or never opted out, gets no such row, and a shopper with many orders gets
  one waiting row, not one per order. The store's own record of an
  opt-out holds even when Smaily cannot be reached or a write to Smaily
  failed; an older "yes" on the Smaily contact never overrides a newer "no"
  made in the store (the store writes its "no" back to the contact instead).
  An opt-out recorded on the Smaily contact (`smaily_rec_profiling` = 0)
  reaches Campaign Intelligence too, the next time the store reads the
  shopper's preference — when they open **My Account > Personalization** or
  log in. When a shopper who opted out
  logs in, their earlier anonymous browsing is not linked to their account.
- **Unsubscribing from marketing also stops profiling.** When Campaign
  Intelligence is connected, a newsletter unsubscribe — in the store, or in
  Smaily and mirrored back — also opts the shopper out of personalized
  recommendations. Subscribing again — in the store, or in Smaily and
  mirrored back — turns profiling back on, and Campaign Intelligence is told
  through the same queued delivery. It stays off when the shopper also
  opted out of personalized recommendations on their own, under
  **My Account > Personalization** or in Smaily.
- **A guest's email is stored on the cart before the guest submits it.**
  While the abandoned-cart automation is on, the email a guest types on
  Magento's own checkout is saved to the cart (Magento's own customer email
  field of the cart) once it is a valid address — before the guest clicks
  **Next** on the shipping step or places the order (see
  [Abandoned cart](#abandoned-cart)). Nothing is sent to Smaily then: the
  cart's address reaches Smaily only with a reminder, once the cart is idle
  past the cutoff. The address stays on the cart until Magento deletes the cart (its
  **Quote Lifetime** setting under **Stores > Configuration > Sales >
  Checkout > Shopping Cart**). Mention this in your privacy notice where it
  describes abandoned-cart reminders.
- **Data subject requests**:
  `bin/magento smaily:gdpr export <email>` (Art. 15) and
  `bin/magento smaily:gdpr erase <email> --force` (Art. 17, idempotent).
  Both cover the Campaign Intelligence record **and** the module's own
  tables — the two delivery queues and the abandoned-cart tracker.
- **What the erasure does locally.** A queued message that could still be
  sent (`pending` or `sending`) is **deleted** — not sending it is the point
  of the request. A message that is over (`sent` or `failed`) is
  **anonymized and kept**, so you keep your own record that you messaged
  this person: the row keeps its type, status, attempts and timestamps, and
  its Entity, payload, response and error all read `[erased]`. Such a row
  can no longer be retried from the Log. The contact's abandoned-cart record
  is **anonymized and kept**: the address is removed and the record is marked
  erased, but the record itself stays, because it is what tells the extension
  that this cart has already been dealt with — remove it and a cart that is
  still sitting in the store would be picked up as a fresh abandoned cart and
  a reminder sent to the address you just erased. A cart still open in the
  store that holds the address — on the cart, or on its billing or shipping
  address — gets such an erased record too, also one the extension has not
  dealt with yet (idle for less than the cutoff, or a guest's typed email), so
  no abandoned-cart reminder goes out for it after the erasure; the cart
  itself is not changed, and it counts among the anonymized carts the command
  prints. The erased record is not kept
  forever: it goes with the ordinary 30-day tidy-up above, or sooner if the
  cart itself is deleted. If that shopper's cart later turns into an order,
  the record stays marked erased. If they come back, type their address at
  checkout and tick the newsletter box themselves, that new address is
  stored — it is their own fresh choice — but the record stays erased, so
  no abandoned-cart reminder is sent for it.
- **What it prints.** One line per place it reached, then the engine:

  ```
  Queued messages: 1 removed, 1 anonymized
  Engine queue: 1 removed, 0 anonymized
  Abandoned carts: 0 removed, 1 anonymized
  Erased engine data for shopper@example.com.
  ```

  The local part runs first, so a Campaign Intelligence outage never blocks
  it: the command then exits non-zero naming the failure, and you re-run it
  later to finish the engine half. `export` prints the same set as JSON
  (`engine` plus `local`), so export and erase always agree.
- Magento-side customer data is handled by Magento's own tooling; Smaily
  contact deletion is done in the Smaily UI.

## CLI reference

| Command | Purpose |
|---|---|
| `smaily:backfill:start [contacts\|catalog\|customers\|orders] [--website=N]` | Start a historical import |
| `smaily:backfill:status` | Show import progress |
| `smaily:engine:ping` | Campaign Intelligence health check |
| `smaily:engine:disconnect --force` | Remove the local engine connection |
| `smaily:gdpr export\|erase <email> [--force]` | Data subject export / erasure (engine + local queues) |

## FAQ

**Nothing is syncing.** Check that Magento cron runs (`bin/magento
cron:run --group smaily_connect` manually to test), the Smaily connection
shows *Connected* (the Dashboard says *Not connected* when Smaily has not
accepted the saved credentials), and look at the Log for errors — the
Dashboard verdict points there when deliveries fail.

**A contact unsubscribed in Smaily but is still subscribed in Magento.**
Reconciliation runs every 15 minutes and only in *Subscribers only
(consent)* mode.

**The abandoned cart email never arrives.** The automation must be enabled
with a workflow selected; the cart needs an email address (a guest's cart on
Magento's own checkout gets one two seconds after the guest types a valid
address in the email field — see [Abandoned cart](#abandoned-cart)); the
cart must be idle past the cutoff but younger than 24 h; each cart is only
ever mailed once, and an address that got a reminder for another cart in the
last 24 hours gets none (the Log shows that row as *Skipped*); and Smaily
must already have the address as a contact that has not unsubscribed.

**The checkout checkbox doesn't show.** It renders on the Luma/Knockout
checkout payment step — including Hyvä's default Luma-fallback checkout.
The commercial Hyvä Checkout product is a different integration surface and
is not supported (see [HYVA_SUPPORT.md](HYVA_SUPPORT.md)).
For Mageplaza One Step Checkout, see
[Third-party one-step checkouts](#third-party-one-step-checkouts).

**My store runs a headless storefront.** The server-side features work as
they are; the browse tracker, campaign-click capture, checkout checkbox and
personalization page are Magento theme parts a separate storefront does not
draw, and product links need a check before recommendation emails go out —
the **Storefront URL** setting puts them on the storefront's address (see
[A separate storefront](#a-separate-storefront)).
See [HEADLESS_STOREFRONTS.md](HEADLESS_STOREFRONTS.md) for the full list
and the hand-off for the storefront team.

**Where did the sync frequency setting go?** v3 syncs in near-real-time via
observers plus a 15-minute consent reconcile; the old 4h/12h/daily presets
are obsolete.

**What languages does the module speak?** English and Estonian — the admin
and storefront follow the configured Magento locale (`et_EE` for Estonian).
Translation packs live in the module's `i18n/` directory; contributions for
other languages are welcome.
