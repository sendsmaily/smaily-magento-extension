# Smaily Connect for Magento 2 — User Guide

Smaily Connect keeps your newsletter audience, marketing automations and
(optionally) Smaily Campaign Intelligence in sync with your Magento store.

- [Installation](#installation)
- [Finding your way around](#finding-your-way-around)
- [Connecting your Smaily account](#connecting-your-smaily-account)
- [Contact synchronisation](#contact-synchronisation)
- [Automations](#automations)
- [Abandoned cart](#abandoned-cart)
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
| **Dashboard** | The landing page: a one-sentence health verdict, connection status for Smaily / Campaign Intelligence / browse tracking, operational counters (deliveries, failures) and the latest queue activity. Every number is a real local queue query. |
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
website it is assigned to.

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
live workflow lists built in. Completed steps stay unlocked in the step
list (a rail on the left; a bar across the top on narrow screens), so you
can move back and forward between them freely — also when
revisiting the initial setup after finishing it. The Overview step says
that Smaily Connect is syncing only when Smaily accepted the saved
credentials; otherwise it says that syncing starts once Smaily accepts them
and points you to **Settings > Connection**. It ends with **Go to
Dashboard** and **Open Settings**. Everything it writes
lands in the regular configuration, so you can fine-tune it later on the
**Settings > Connection** tab — its own Test Connection / Save Connection
footer and connection-status line (Connected / Not connected, with the
account name once connected).

**Connected** means that Smaily accepted the saved credentials the last
time they were checked: when you pressed Test Connection, or when you saved
the connection (each save asks Smaily once). Filled-in fields alone are not
enough. Changed credentials show *Not connected* until they are checked, and
if Smaily later refuses them — for example because the API user was
removed — the status turns to *Not connected* too. The Dashboard then shows
a **Not connected** verdict with an **Open Connection settings** button;
press Test Connection there to check the credentials again.

When the Smaily account's package does not include API access, Smaily
refuses every request before it looks at the credentials (Smaily response
code 227, "A paid package is required"). The store then shows *Not
connected* as well — nothing can reach Smaily — but Test Connection, the
connection status, a configuration save, the Dashboard and the Log name
the package as the reason instead of calling the credentials wrong. The
credentials cannot be checked until the account is on a package that
includes the API; press Test Connection again after the package is changed.

| Field | Notes |
|---|---|
| Subdomain | Your Smaily subdomain. Pasting the full URL (`https://demo.sendsmaily.net`) also works — it is normalized on save. The subdomain must be a plain one, such as `demo`: letters, digits and hyphens only. Any other value is refused on save and on Test Connection, and nothing is sent to Smaily with it. |
| API Username / Password | Create these in Smaily under *Preferences > API*. The password is stored encrypted. |
| Multilingual Mode | See [Multilingual stores](#multilingual-stores). |

Use the **Test Connection** button next to the fields for instant feedback
on the credentials as typed — no save needed; a successful test also
refreshes the automation workflow dropdowns. With the password field left
blank, Test Connection uses the saved password, as long as the subdomain and
username are filled in. With empty fields and nothing saved to fall back on,
it asks you to fill in the subdomain, username and password. Saving succeeds whenever the
subdomain is a plain one; if the saved credentials are wrong you get a clear
warning instead of a blocked save.

Credentials can be set per **website**, or per **store view** when each
language uses its own Smaily account (multilingual mode "Per-language
Smaily accounts" — the Connection panel manages those store-view
credentials for you, see below).



### Multilingual stores

A store view's language is derived from its locale (`et_EE` → `et`). As
soon as your store views speak more than one language, the Connection
panel (initial setup step 1 and Settings > Connection) opens with a
**routing-mode choice** — four cards; the panels below adapt live to the
selected card, nothing is saved until you press Save/Continue:

| Mode | Meaning |
|---|---|
| Single language | One account, one workflow per trigger (default). |
| Per-language Smaily accounts | Each language has its own Smaily account. The Connection panel shows one credential block per detected language, each with its own Test Connection button, plus a **default fallback account** picker. |
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
override. Switching the multilingual mode away from per-language accounts
removes the per-store-view credential overrides (the panel asks for
confirmation first); workflow mappings are kept but stop being used
outside the per-language modes.

---

## Contact synchronisation

**Settings > Contacts** (or Initial setup step 2)

Contacts sync in near-real-time through a durable queue (no lost events
if Smaily is briefly unreachable — deliveries retry with backoff).

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
- **Include Guest Order Emails** — also sync the emails of guest orders
  placed without the checkout newsletter opt-in. It applies only under
  **All customers**. Under **Subscribers only** and **Checkout opt-in only**
  a guest's email reaches Smaily only when the guest ticks the checkout
  newsletter checkbox, whatever this option says.
- **Show Newsletter Checkbox At Checkout** — adds an opt-in checkbox to the
  checkout payment step; ticking it creates a real Magento newsletter
  subscriber (double opt-in is honoured if your store requires
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
sent and says why. A shopper who unsubscribed in the store is sent as
unsubscribed with this field.

## Abandoned cart

A cart counts as abandoned when it has items and an email address and has
been idle past the **cutoff** (default 30 minutes, minimum 10). The email can
come from a signed-in customer, an order in progress, or simply the address a
guest typed at checkout — so a guest who enters their email and abandons at the
shipping step is still reminded, without ever reaching the payment step. Carts
older than 24 hours are never mailed — a recovering cron never blasts stale
reminders. Each cart is mailed **once**.

The automation receives up to 10 products as numbered fields
(`product_name_1`, `product_sku_1`, `product_quantity_1`, `product_price_1`
(incl. tax), `product_base_price_1`, `product_description_1`,
`product_image_url_1`, … plus `over_10_products` when the cart is bigger).
There is nothing to configure: every product field is always sent, and all
ten slots are sent on every reminder — the ones the cart does not use are
sent empty, which is what clears a previous, larger cart from the contact.
Your Smaily template decides which of them to show.

`{{abandoned_cart_url}}` is a secure recovery link that restores the exact
cart when clicked (a signed link; carts belonging to a registered customer
ask them to sign in first). The link expires 30 days after the reminder is
created: an expired link opens the cart page with the notice "This cart link
has expired." and restores nothing.

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
products are listed; configurable variants resolve to their parent. The feed
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
| Catalog | On product save/delete (deletes become out-of-stock) and on every stock change — a shipment that sells the last unit, a credit memo that puts it back, an Advanced Inventory or Sources edit, an API stock update. A nightly re-sync (03:40 store time) catches what no event can see, such as a CSV/`bin/magento import` run that writes the tables directly |
| Customers | On profile create/update (no consent fields — the engine is a separate lawful surface) |
| Orders | On order placement, status changes, and refunds — a credit memo re-syncs the order, so a fully credited line is reported as returned and stops being recommended back to that customer (a partly credited line still counts as kept) |
| Browse events | Product views, searches, cart adds, checkout — batched from the storefront (**Enable storefront browse tracking (product views, searches, cart activity)**, off by default — a separate, consent-gated toggle, not part of the always-on sync above) |

Browse tracking respects Magento's cookie restriction mode and sends events
through your own server (`smaily/relay`) so the API key never reaches the
browser.

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
  goes out in order once the account is active again.
- **Settings > Intelligence** says the account is not active, links to your
  Smaily account and offers **Check again**. The engine-bound historical
  imports are unavailable meanwhile; the contact import on the
  **Contacts** tab is unaffected, as is all Smaily email sending.
- The Dashboard verdict names the deactivated account rather than
  reporting an outage, and an admin notification says the same. Waiting
  does not fix it — only Smaily can make the account active again.

Once Smaily tells you the account is active, press **Check again**. The
health check asks the engine again on its own every 15 minutes, so sending
also resumes without you pressing anything.

### Engine automations

The engine-run triggers live right under your regular automations on
**Settings > Automations**, which lists the triggers available to your
sector. Each row maps a trigger to a
Smaily workflow with a cooldown, an optional daily cap and a **test mode**
(on by default — fires reach only the listed test emails until you turn it
off). Nothing is enabled without your explicit action.

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
  The import obeys that website's **Sync contacts to Smaily**
  switch exactly like the live syncs do: with the switch off the import
  button is disabled and says so, and an import started any other way
  (the CLI, or one already queued when you switched it off) sends nothing
  and finishes at 0. An import that was already running when you switched
  it off stops at its next chunk and is reported as cancelled, keeping the
  count it had genuinely sent.
- **Catalog / Customers / Orders → Campaign Intelligence** — Settings >
  **Intelligence** tab

Each import button shows live progress right where you started it, and a
**Cancel import** button appears while a job is running — the background
worker stops cleanly at its next page boundary. A cancelled import is
terminal: starting the same import again begins a fresh run from the
beginning. (An import interrupted by an error, on the other hand, resumes
from its last cursor.) Imports are safe to re-run either way: deliveries
are deduplicated on the receiving side.

After an import finishes, its outcome and timestamp stay visible on the
panel — you do not have to keep the page open:

- **"Done, X of Y synced"** — everything landed.
- **"Done, X of Y synced — N failed"** — individual items failed
  permanently, with a link to the Log pre-filtered to those rows. Items
  still waiting for an automatic retry are *not* counted as failed.
- **"Stopped before an error"** — the job itself hit an error and stopped
  at a page boundary; nothing was lost, press the import button to run it
  again.
- **"Cancelled"** — stopped on your request; starting again begins a
  fresh import.

## The log and troubleshooting

- **Marketing > Smaily Connect > Log** — every delivery in one grid:
  Smaily (contact syncs, automation triggers) and Campaign Intelligence
  (catalog, customers, orders, browse events), told apart by the
  **Source** column, with status, attempts and the last error. The error
  column shows what the other side actually said, not our internal name
  for the failure. A Smaily delivery error reads in your admin language,
  whatever the language of the store that sent the row; the log file
  records it in English. Select failed rows and **Retry** — each row is routed
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
  that contact, or the contact's data was erased under Art. 17.
- **Withdrawn** is its own status in the grid and in the status filter: a
  reminder the store called back because the shopper bought in the
  meantime. Nothing was delivered and nothing failed, so it is labelled as
  neither.
- **Details** on any row opens a slide-out with the full picture: the
  payload exactly as it was (or will be) sent, the attempt count, when the
  next automatic retry happens (or an honest "this row will not retry on
  its own"), the last error — with our internal failure class beside it —
  and the last API response: the HTTP status and what Smaily or Campaign
  Intelligence answered. A row that went out with others in one request
  shows only its own part of it. A retry keeps the evidence of the attempt
  before it: when a later attempt gets no answer at all (a network
  failure), the last response that did arrive stays. A row that never
  reached the server — skipped because no workflow is mapped, withdrawn,
  or stopped before any request — says that nothing was sent for it.
  Sensitive values (passwords, API keys) are never shown, and email
  addresses are masked.
- When deliveries failed in the last 24 hours, a banner above the grid
  says so and links straight to the grid pre-filtered to failed rows; the
  dashboard's failed-deliveries tile links to the same view.
- Deliveries retry automatically with backoff (1 min → 6 h, 5 attempts)
  before parking as *failed* for manual retry. A delivery that was refused
  outright — wrong credentials, a deleted workflow, a rejected address —
  is not retried at all: it is marked *failed* immediately, with the
  refusal in the last error, so the failed count tells you now instead of
  six hours later. When Smaily asks the store to slow down, the row waits
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
  payloads are summarised and email addresses masked, and a network failure
  is logged with the address it called but without its query string or the
  contact's address — but a message that Smaily or Campaign Intelligence
  sends back can still carry a contact's email address. Treat the file as personal
  data, switch the level back to `error` when you are done, and delete
  the lines you no longer need.
- Sent queue rows are pruned after 30 days, failed rows after 90. The same
  nightly job also tidies the abandoned-cart tracker — the small table that
  remembers which carts the extension has already dealt with: a finished
  record (reminded, purchased, expired, erased) is dropped 30 days on, and
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
  A delivery that waits while the shopper changes their mind is not sent —
  only the newest choice reaches the engine. The store's own record of an
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
- **Data subject requests**:
  `bin/magento smaily:gdpr export <email>` (Art. 15) and
  `bin/magento smaily:gdpr erase <email> --force` (Art. 17, idempotent).
  Both cover the Campaign Intelligence record **and** the module's own
  tables — the two delivery queues and the abandoned-cart tracker.
- **What the erasure does locally.** A queued message that could still be
  sent (`pending` or `sending`) is **deleted** — not sending it is the point
  of the request. A message that is over (`sent` or `failed`) is
  **anonymised and kept**, so you keep your own record that you messaged
  this person: the row keeps its type, status, attempts and timestamps, and
  its Entity, payload, response and error all read `[erased]`. Such a row
  can no longer be retried from the Log. The contact's abandoned-cart record
  is **anonymised and kept**: the address is removed and the record is marked
  erased, but the record itself stays, because it is what tells the extension
  that this cart has already been dealt with — remove it and a cart that is
  still sitting in the store would be picked up as a fresh abandoned cart and
  a reminder sent to the address you just erased. The erased record is not kept
  forever: it goes with the ordinary 30-day tidy-up above, or sooner if the
  cart itself is deleted. If that shopper's cart later turns into an order,
  the record stays marked erased. If they come back, type their address at
  checkout and tick the newsletter box themselves, that new address is
  stored — it is their own fresh choice — but the record stays erased, so
  no abandoned-cart reminder is sent for it.
- **What it prints.** One line per place it reached, then the engine:

  ```
  Queued messages: 1 removed, 1 anonymised
  Engine queue: 1 removed, 0 anonymised
  Abandoned carts: 0 removed, 1 anonymised
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
with a workflow selected; the cart needs an email address (guest carts get
one at checkout's email step); the cart must be idle past the cutoff but
younger than 24 h; and each cart is only ever mailed once.

**The checkout checkbox doesn't show.** It renders on the Luma/Knockout
checkout payment step — including Hyvä's default Luma-fallback checkout.
The commercial Hyvä Checkout product is a different integration surface and
is not supported (see [HYVA_SUPPORT.md](HYVA_SUPPORT.md)).

**Where did the sync frequency setting go?** v3 syncs in near-real-time via
observers plus a 15-minute consent reconcile; the old 4h/12h/daily presets
are obsolete.

**What languages does the module speak?** English and Estonian — the admin
and storefront follow the configured Magento locale (`et_EE` for Estonian).
Translation packs live in the module's `i18n/` directory; contributions for
other languages are welcome.
