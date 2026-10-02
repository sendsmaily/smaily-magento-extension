# Pilot-day smoke checklist (internal)

Internal working document for PRO-2474 — Erkki's checklist for the first
pilot store's install day. Not public documentation and not shipped: the
release ZIP excludes it (`bin/build-release-zip.sh`, gated by
`bin/verify-release-zip.sh`). The merchant-facing install steps are in
`docs/INSTALLING.md`; this file assumes the pilot's developer has run them.

Admin labels below are the en_US labels exactly as the admin shows them.
Paths: **Marketing > Smaily Connect > Dashboard / Initial setup / Settings /
Log**.

## Data rule — read first

Use **your own test contact** for every step: an address you own (for
example a `+pilot` alias of your own mailbox), your own name, your own test
customer account on the pilot store. **Never** use a real customer's email,
name, account or order — not to look something up, not to "just check one".
Do not copy addresses or order details from the Log into notes, Linear or
chat; the Log's Details panel masks addresses on purpose. No credentials or
setup tokens in this file, in Linear or in chat either.

Agree with the merchant beforehand: which payment method the test order
uses, and that you cancel or refund it afterwards.

## 0. Pre-flight

| What to do | Where to look / what good looks like |
|---|---|
| Confirm the store has one website. | Stores > All Stores lists one website. More than one: Initial setup opens with "Which website are you setting up?" and Settings shows a **Website** selector — onboard each website, and note it in PRO-2474. |
| Confirm the storefront theme is Luma-based, not Hyvä. | Content > Design > Configuration. A Hyvä theme needs the separate compat module (`compat/hyva`), which the ZIP does not carry — stop and raise it. |
| Confirm the ZIP the developer installed is the release build. | Developer ran `sha256sum -c smaily-connect-magento2.zip.sha256` → `OK`. `bin/magento module:status Smaily_Connect` → `Module is enabled`. |
| Confirm the store's Magento cron is installed. | `crontab -l` on the server shows the `#~ MAGENTO START` block running `bin/magento cron:run` every minute. |

## 1. Connect the Smaily account (Initial setup)

| What to do | Where to look / what good looks like |
|---|---|
| Open **Marketing > Smaily Connect**. | A fresh install lands on **Initial setup**, step bar: **Connect, Contacts, Automations, Intelligence, Overview**. |
| **Connect** step: fill **Subdomain**, **API username**, **API password**; press **Test connection**. | "Connected!" and a green status with the account name; **Continue** goes to **Contacts**. A failure shows the Smaily-side reason — fix the credentials, do not continue. |
| **Contacts** step: keep **Sync contacts to Smaily** on, mode **Subscribers only (consent)** unless the merchant decided otherwise; keep **Show a newsletter checkbox at checkout** on. **Continue**. | The step saves ("Saving…" then the next step). |
| **Automations** step: map **Abandoned cart** to the merchant's Smaily workflow and tick **Enabled** (needed for §5). Map **Welcome** / **First order** only if the merchant has those workflows. **Wait (minutes)** stays at **30** unless the merchant asked otherwise. | Workflow dropdowns list the Smaily account's workflows (**Refresh workflows** if one is missing). |

## 2. Engine setup exchange (Intelligence step)

| What to do | Where to look / what good looks like |
|---|---|
| Get a one-time setup URL/token for the store's existing Campaign Intelligence tenant from the engine side. Paste it into **Setup URL or token from Smaily** and press **Connect**. | "Connected: <tenant> (engine <version>)" — the tenant must be the pilot store's tenant, not a test tenant. The token is one-time and never stored — if the exchange fails, ask for a new token rather than retrying the old one. |
| Tick **Enable storefront browse tracking (product views, searches, cart activity)** (needed for §6). | Saved with the step. |
| **Overview** step: **Finish**. | "You are all set!"; after that, Smaily Connect pages open the **Dashboard** instead of Initial setup. |
| On the server: `bin/magento smaily:engine:ping`. | `Connected. Tenant: …, engine version: …, ping: {…}`. "Ping failed: …" is red — see §9. |
| Dashboard. | Connection strip: **Smaily** and **Campaign Intelligence** both **Connected**; **Browse tracking** shows **Script live on storefront**. |
| Decide with the engine side whether the existing tenant needs the store's history. If it does: **Settings > Intelligence** → **Historical imports to Campaign Intelligence** → **Import catalog**, **Import customers**, **Import orders**. | Live sync covers changes from now on only. Each import shows "Importing… n / total" and ends "Done, n of total synced." — one chunk per cron minute. |

## 3. Contact sync (one test subscriber)

| What to do | Where to look / what good looks like |
|---|---|
| On the storefront, subscribe your test address through the newsletter form (footer). If the store requires newsletter confirmation, click the confirmation link first. | **Log**: a new row, **Source** Smaily, **Type** `contact.sync`, **Status** Pending → **Sent** within a minute or two. |
| In Smaily, open Contacts and search your test address. | The contact exists and is subscribed. |

## 4. One order (Smaily + engine)

| What to do | Where to look / what good looks like |
|---|---|
| Log in as your own test customer, place one order with the agreed payment method, tick **Subscribe to our newsletter** at the payment step. | Order placed. |
| **Log** (newest rows first). | **Source** Smaily: `contact.sync` → **Sent** when the checkbox made a new subscription (plus `automation.trigger` → **Sent** if **First order** is mapped and this is the test customer's first order). **Source** Campaign Intelligence: **Type** `orders` → **Sent**. |
| In Smaily: the test contact. In the engine tenant: the order. | Contact updated in Smaily; the order is present on the engine side for the pilot tenant. |
| Cancel or refund the test order as agreed. | A new Campaign Intelligence `orders` row → **Sent** (status changes and credit memos re-sync the order). |

## 5. One abandoned cart

Timing, from the code defaults: a cart counts as abandoned once it has an
email and has been idle for the **Wait (minutes)** value — default **30**
(`smaily_connect/automations/abandoned_cutoff`, minimum 10). The detector
runs every 5 minutes (`smaily_abandoned_cart`), and the delivery flush every
minute. So the reminder is queued **30–35 minutes** after the last change to
the cart and sent about a minute later. Carts older than 24 hours are never
mailed; each cart is mailed once.

| What to do | Where to look / what good looks like |
|---|---|
| As your test customer (logged in), add a product to the cart. Do not touch the cart afterwards and **do not buy it** — a purchase withdraws the reminder. | — |
| Wait ~35 minutes. | **Log**: **Source** Smaily, **Type** `automation.trigger` → **Sent**. **Details** shows the payload with `product_name_1` … and the `abandoned_cart_url`. |
| Check your test mailbox. | The merchant's abandoned-cart email arrives; the recovery link restores the cart (asks you to sign in first). |
| A row with **Status** **Withdrawn**. | Not an error: the cart was bought before the reminder went out. |

## 6. One browse event (identified visitor)

Browse events are not queued, so they **never appear in the Log** — they go
from the storefront through the store's own `smaily/relay` endpoint straight
to the engine. "Identified" means the browser carries the engine's visitor
token: it is set from the `smaily_vt` link parameter when someone arrives
from a Smaily email link, and it is only sent when cookies are allowed (if
the store uses cookie restriction mode, accept the cookie notice first).

| What to do | Where to look / what good looks like |
|---|---|
| Send your test contact a Smaily email with a link to the store that carries the visitor token (an engine-rendered link such as a recommendation block — confirm with the engine side which links carry it), and click it in a desktop browser. | Browser dev tools > Application > Cookies: `smaily_rec_uid` (the default name; the engine can rename it) is set for the store domain. |
| Open one product page and stay ~5 seconds (the tracker batches for 5 s, or sends on leaving the page). | Dev tools > Network: a `POST …/smaily/relay` answered **200** with `{"ok":true,"accepted":…}`; the request payload has `event_type: "product_view"` and a `smaily_visitor_token`. A **404** means browse tracking is off or the engine account is not active. |
| Engine side. | The product view is recorded for the pilot tenant against the test contact's identity. |

## 7. Log reads clean

| What to do | Where to look / what good looks like |
|---|---|
| **Log**, **Status** filter = **Failed**. | No rows. No "failed in the last 24 hours" banner above the grid. |
| **Dashboard**. | Verdict **All systems normal** — "Everything is running — deliveries to Smaily are flowing normally." **Failed** tile at 0. |

## 8. Cron actually runs

| What to do | Where to look / what good looks like |
|---|---|
| Watch any new Log row. | **Pending** turns **Sent** within 1–2 minutes. Rows stuck in **Pending** and the Dashboard's "events waiting to send" growing = cron is not running. |
| On the server (read-only SQL): `SELECT job_code, status, executed_at FROM cron_schedule WHERE job_code LIKE 'smaily_%' ORDER BY scheduled_at DESC LIMIT 10;` | Recent `success` rows for `smaily_flush_event_queue` and `smaily_flush_ingest_queue` (every minute); `smaily_abandoned_cart` every 5 minutes. Only `pending` rows and no `success` = the cron entry is missing or broken. |
| By hand, if in doubt: `bin/magento cron:run --group smaily_connect`. | Pending Log rows move to **Sent** right after. |

## 9. If something is red

1. **Log** → filter **Status** **Failed** (or the failed banner / the
   Dashboard's failed tile, which open the same view) → **Details** on the
   row. The slide-out shows the payload as sent (addresses masked), attempt
   count, next automatic retry or "will not retry on its own", the
   **Last Error** as the other side said it, our failure class beside it,
   and the last API response.
2. Wrong credentials, a deleted workflow or a rejected address fail at
   once and are not retried — fix the cause, then **Send again** on the row
   (or select rows and **Retry**). Network or server errors retry by
   themselves (1 min → 6 h, 5 attempts).
3. More detail: the verbosity control above the Log grid → **debug**, then
   `var/log/smaily_connect.log` on the server. Set it back to **error**
   afterwards.
4. Engine trouble: `bin/magento smaily:engine:ping`; the Dashboard and
   **Settings > Intelligence** say plainly when the Campaign Intelligence
   account is not active (fixed on the Smaily side, then **Check again**).
5. Rollback if the pilot must stop: `docs/INSTALLING.md` § Disabling or
   removing the module — note it drops the six `smaily_*` tables (queues,
   Log, abandoned-cart tracker, automation mapping, import progress, order
   attribution). With `--safe-mode=1` it still drops them but keeps a CSV
   copy of every table with rows, which `setup:upgrade --data-restore=1`
   loads back after re-enabling (verified on a clean sandbox, 2026-10-02).
   Settings in `core_config_data` are kept either way.

Record the outcome (ticked items, anything red, the row's **Last Error**
without personal data) in the PRO-2474 report to the orchestrator.

## After the pilot day

- Erase the test contact if it should not stay:
  `bin/magento smaily:gdpr erase <your test address> --force` (local queues
  + engine), and remove it in Smaily.
- Set the Log verbosity back to **error** if it was raised.
