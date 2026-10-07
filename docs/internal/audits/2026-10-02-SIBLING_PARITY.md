# Sibling parity — what the WooCommerce and Shopify plugins have that Magento lacks (PRO-3574)

**Date:** 2026-10-02
**Compared:** Magento `v3` at `ce446da` (3.0.0-rc1, untagged) against the
WooCommerce plugin `main` at `3afff33` (3.15.0, live) and the Shopify app
`main` at `f7b6410` (2026-09-24), plus the three sibling Linear projects
(WooCommerce v3, Shopify hosted app, Campaign Intelligence engine).
**Baseline:** the 2026-09-10 parity sweep (`d406ef1`). It found that Magento
mirrored the August sibling wave and filed the September wave as PRO-2451,
PRO-2452, PRO-2453, PRO-2454 and PRO-2455. The first four are done. This
audit covers what the siblings shipped after that, plus older gaps that are
still open.

## Method

1. Read the sibling change records after 2026-09-09: WooCommerce `readme.txt`
   changelog 3.13.0, 3.14.0 and 3.15.0 (3.0.0–3.12.1 for older gaps),
   `STATUS.md`, `docs/DECISIONS.md` and `docs/CONTACT_SYNC_MODES.md`;
   Shopify `STATUS.md`, `docs/PARITY_AUDIT.md` and the 35 commits after
   2026-09-09; every sibling Linear issue updated after 2026-09-09.
2. Checked each candidate against Magento **code**, not only the docs (a
   grep or a read of the class that owns the behaviour). The evidence column
   names the file.
3. Searched the Magento v3 and UI/UX parity projects for an issue that
   already covers the gap.
4. Engine contract: `docs/RECENGINE_API_CONTRACT.md` is byte-identical in
   all three repositories (v1.8.1, md5 `39afc210…`). No sibling uses a
   contract feature that Magento cannot read. The gaps below are about
   **how** the contract is used (sender rules, optional fields), not
   about its version.

Effort is at AI-agent speed, including tests and docs: **very low** — about an hour;
**low** — half a day or less; **medium** — one or two days; **high** — more
than that.

## Summary

| # | Gap | Timing | Effort | Magento issue |
|---|---|---|---|---|
| P1 | A malformed `smaily_rec_id` cookie makes the engine reject the whole order. Magento sends the value without checking it. | **before pilot rc1** | very low | none |
| P2 | The "force opt-in" toggle can still re-subscribe contacts who unsubscribed in Smaily. | **before pilot rc1** | low | none |
| P3 | A shopper's profiling opt-out reaches the engine by one direct call. The call is never retried. | **before pilot rc1** | low | none |
| R1 | Profiling consent hardening: a durable local opt-out record, the newest choice wins, a marketing unsubscribe stops profiling, and an opt-out made in Smaily reaches the engine. | **before first public release** | medium | none |
| R2 | The identity merge on login ignores the shopper's profiling opt-out. | **before first public release** | low | none |
| R3 | The My Account "Personalization" page appears on stores without Campaign Intelligence. | **before first public release** | low | none |
| R4 | Campaign Intelligence is not introduced with the agreed text: what it does, that it is a paid add-on, and its price. | **before first public release** | low | none |
| R5 | When the Smaily package does not include the API (code 227), the admin says the credentials were refused. | **before first public release** | low | none |
| R6 | The welcome automation fires on every subscribe. This includes resubscribes and subscriptions an admin, the API or an import created. | **before first public release** | medium | none |
| R7 | The contact import ignores the contact-sync mode and always imports newsletter subscribers. | **before first public release** | low–medium | partly PRO-1970 |
| R8 | `tags.category_defaulted` (contract v1.6.0) is never sent. | **before first public release** | low | PRO-1952 |
| R9 | Removing the module leaves the Smaily password and the engine key in `core_config_data`. | **before first public release** | low | none |
| R10 | The 2.8.x plaintext API password stays in the database after the upgrade. | **before first public release** | low | none (PRO-3575 scope) |
| R11 | Log Details promises "payload as sent" and "last API response". The marketing queue never writes them. | **before first public release** | low–medium | PRO-1965, PRO-1963 |
| L1 | Abandoned-cart reminders carry no `product_url_N` links. | later | low | none |
| L2 | Transactional emails (order and shipping confirmations) | later | high | PRO-1766 |
| L3 | Smaily landing page as a CMS widget | later (3.1) | medium | PRO-2455 |
| L4 | A merge-tag reference for every automation | later | low | none |
| L5 | Smaily envelope rejections (HTTP 200, code 203) use all 5 retries. | later | low | PRO-1962 |
| L6 | Browse identity for a logged-in shopper whose login event never fired (persistent session) | later | low–medium | none |

Counts: **3 before pilot rc1, 11 before first public release, 6 later.**

## Before pilot rc1

The pilot store has a live Campaign Intelligence tenant and real shoppers,
so the defects below reach real people and real engine data from day one.
Each one is a small change.

### P1 — A malformed recommendation id costs the engine the whole order

- **What.** Contract §5: `smaily_rec_id` is UUID-validated, and *"a bad value
  costs the order — not just the field"*. Sender rule: omit the field when
  the cookie is not a well-formed UUID. Magento copies the URL parameter into
  the cookie unchecked. This includes the `utm_content` fallback, which by
  definition never holds an engine rec id. Magento then copies the cookie onto
  the order unchecked. The other attribution values (visitor token, context,
  session id) have no shape check either, apart from a 255-character cap.
- **Evidence.** Siblings: WooCommerce `includes/Smaily/RecEngine/Support/RecId.php`
  and `AttributionShape.php`, checked at capture and at send
  (readme 3.11.0 "a malformed recommendation id … no longer travels onto
  the order", 3.11.2; PRO-1896, PRO-1942). Shopify
  `app/lib/engine/order-builder.server.ts:292` (PRO-1713). Magento
  `Model/Engine/Payload/OrderPayloadBuilder.php:237-245` forwards
  `rec_id` as stored; `view/frontend/web/js/attribution.js:55-62` (and the
  Hyvä twin `compat/hyva/view/frontend/web/js/smaily-attribution.js`) writes
  any value; `Model/Engine/AttributionManager.php::cookie()` checks only the length.
  The browse path already drops the rec id (`BrowseEventValidator.php:60-68`).
- **Covered?** No. PRO-1484 verified the click capture, not the value's shape.
- **Effort.** Very low: a UUID check in the order builder, and the same
  check before the cookie is written (both storefront scripts).
- **Why rc1.** Data loss. A truncated link or a test-email placeholder
  silently removes a paying order from the engine. Attribution and revenue
  are exactly what the pilot measures.

### P2 — The force opt-in toggle still exists

- **What.** Under the legitimate-interest mode, Settings > Contacts offers
  a toggle. When it is on, every welcome, first-order and abandoned-cart
  trigger sends `force_opt_in=true`, which re-subscribes a contact who
  unsubscribed in Smaily. WooCommerce retired this toggle: a trigger never
  overrides an unsubscribe, in any mode, because the GDPR Art. 21 objection to
  direct marketing is absolute. Erkki approved this on 2026-08-04.
- **Evidence.** WooCommerce readme 3.11.0 ("the force opt-in choice on
  automation triggers is retired"), `docs/DECISIONS.md` "PRO-1716", the
  uninstall scrub in PRO-1897. Magento `Model/ContactSync/Mode.php:90-101`,
  `Model/Queue/Handler/AutomationHandler.php:96`,
  `view/adminhtml/templates/panel/subscribers.phtml:111-124`,
  `Model/Config.php:33`, and `docs/USER_GUIDE.md:257-260`, which still
  describes the advanced toggle.
- **Covered?** No Magento issue.
- **Effort.** Low: always send `false`, remove the control and its two
  phrases (EN + ET), leave the stored row unread (as WooCommerce does),
  and update the guide. No data change.
- **Why rc1.** Privacy and sender reputation. This is a merchant-reachable
  path to mailing people who unsubscribed. The pilot store runs this
  admin. The decision is already made on the sibling.

### P3 — An opt-out that fails to reach the engine once is never retried

- **What.** `ProfilingConsent::setAllowed()` writes the choice to Smaily,
  then calls the engine's §10 opt-out **once**, synchronously. A failure
  is logged and dropped. Nothing calls it again: Magento has no read path
  that re-sends the opt-out. WooCommerce sends the engine opt-out again on
  every read that resolves to "do not profile" (`ProfilingConsent::refresh()`),
  so an opt-out reaches the engine eventually.
- **Evidence.** Magento `Model/Privacy/ProfilingConsent.php:97-118`; the
  only callers are `Controller/Privacy/Save.php` and
  `ViewModel/PrivacyForm.php`. WooCommerce
  `includes/Privacy/ProfilingConsent.php::refresh()` (engine opt-out on
  every "no" answer) and readme 3.7.0.
- **Covered?** No.
- **Effort.** Low. Queue the engine opt-out (and the opt-in) as a row on
  the existing engine queue, so it gets the normal retry, the refused-account
  gate (PRO-2451) and a Log row. A dedicated event type is enough. No
  schema change.
- **Why rc1.** Privacy: a real shopper's Art. 21 objection can be lost
  without trace on the pilot store. The rest of the profiling hardening
  (R1) is larger and can follow.

## Before first public release

### R1 — Profiling consent hardening

- **What.** Magento reads the profiling preference from the Smaily contact,
  caches the answer for a day and **fails open**. WooCommerce has moved well past
  that:
  - a durable local opt-out record that a Smaily outage or a failed Smaily
    write cannot undo (PRO-1194, PRO-3191);
  - the newest choice wins: an older `1` on the contact, a `1` with no
    timestamp, or a malformed or future-dated timestamp does not lift a newer
    store-side opt-out (PRO-3192, PRO-3434);
  - `is_unsubscribed = 1` also means "do not profile";
  - a "no" read back from Smaily (an opt-out made on the Smaily side) is
    carried to the engine;
  - the My Account page shows only a preference that is actually known
    (PRO-3189).

  In Magento, a failed Smaily write means the page shows "allowed" again a day later,
  and a Smaily-side opt-out never reaches the engine.
- **Evidence.** WooCommerce readme 3.7.0, 3.14.0 and 3.15.0;
  `includes/Privacy/ProfilingConsent.php` (`is_allowed()`, `refresh()`,
  `is_newer_opt_in()`, `fallback_on_error()`); DECISIONS "PRO-3191",
  "PRO-3192". Magento `Model/Privacy/ProfilingConsent.php:46-79` checks
  only `smaily_rec_profiling === '0'`, with no timestamp comparison, no
  durable record and no engine call on read.
- **Covered?** No.
- **Effort.** Medium. The durable record needs a home (a flag row or a
  config path, so no new table). Whether a marketing unsubscribe also stops
  profiling is a consent rule, so Erkki confirms it (WooCommerce decision
  F3-31 says yes).
- **Why before public.** Privacy. P3 already closes the worst hole (a lost
  engine opt-out). The rest is about correctness over time.

### R2 — The login identity merge ignores the profiling opt-out

- **What.** On login, Magento queues an identity merge that binds the
  shopper's anonymous browse history to their email. WooCommerce skips the
  merge when the shopper has opted out of profiling, so their browsing stays
  anonymous. The contract makes the engine's §10 exclusion the guarantee and
  calls sender omission a courtesy. Even so, merging an opted-out person's
  history is profiling data processing the person said no to.
- **Evidence.** WooCommerce
  `includes/Integrations/WooCommerce/IdentityHookHandler.php:97` (readme
  3.8.0). Magento `Observer/Engine/CustomerLogin.php` (no consent check).
  Shopify has no gate either.
- **Covered?** No.
- **Effort.** Low, once R1 gives a reliable answer. It can ship with R1.

### R3 — The "Personalization" page shows on every store

- **What.** The My Account "Personalization" link and page render on every
  store, including stores that never connected Campaign Intelligence.
  Saving the page writes `smaily_rec_profiling` to Smaily through the
  contact upsert. For a customer Smaily does not know, the upsert creates a
  contact. Whether Smaily's create default makes that contact *subscribed*
  is unverified (hypothesis — the Shopify team relies on that default for
  first-seen opt-ins). WooCommerce shows the section only where Campaign
  Intelligence is connected and active.
- **Evidence.** WooCommerce readme 3.14.0, PRO-2513 (`8c26eed`). Magento
  `view/frontend/layout/customer_account.xml:11-19` (no condition) and
  `Controller/Privacy/Save.php` (no engine check).
- **Covered?** No.
- **Effort.** Low: a link block with a connected-and-sending check, and a
  404 or redirect on the page and the save action when the engine is not live.
- **Why before public.** Merchant and shopper confusion on the many stores
  that use only Smaily email, plus the possible unwanted contact creation.
  The pilot has Campaign Intelligence, so the pilot is unaffected.
- **Open:** does a `POST contact` carrying only the profiling fields create
  a subscribed contact? Erkki or the Smaily API team answers. The answer
  decides whether the save action also needs a not-found guard.

### R4 — Campaign Intelligence introduction and price

- **What.** Both siblings now introduce Campaign Intelligence with
  marketing's agreed text (GMS-11): what it does with product, customer and
  order data, and that it is an optional paid add-on priced per month on
  top of the Smaily subscription, activated by contacting Smaily. The text
  shows only while the store is not connected. Magento shows its own
  one-line description ("Skip this step if you do not have an Intelligence
  subscription …") with no price and no activation path.
- **Evidence.** WooCommerce readme 3.11.2 and 3.13.0, PRO-2298, PRO-1725
  (a promotional disclaimer, in progress), `Step4Recommendations.tsx:122`;
  Shopify PRO-2457 (`4e58582`). Magento `i18n/en_US.csv:385`,
  `view/adminhtml/templates/panel/intelligence.phtml`.
- **Covered?** No.
- **Effort.** Low. The EN and ET strings exist in the WooCommerce
  `.pot`/`-et.po`. Copy them through Erkki (a user-visible copy change).
- **Why before public.** Commercial transparency. A paid feature that is
  unexplained in the admin causes merchant confusion and support load, and
  the Marketplace listing (PRO-1198) will make the same claim.

### R5 — A package that blocks the API reads as refused credentials

- **What.** When the Smaily package does not include the API, Smaily answers
  `HTTP 403 {"code":227}` before it checks the credentials. Magento treats
  every 401/403 as a credential refusal. The Dashboard then says "Not
  connected — the Smaily credentials are missing or Smaily has not accepted
  them", and the Log calls queued deliveries refused. WooCommerce names this
  case ("your Smaily package does not include the API").
- **Evidence.** WooCommerce `includes/Smaily/RefusalReason.php` (PRO-1686,
  readme 3.11.0), probed against a live freemium account. A support ticket
  in September (PRO-3366) was this exact message on a sibling store.
  Magento `Model/Client/SmailyClient.php:152-155` and
  `Model/Client/VerifiedCredentials.php`; no `227` anywhere in `Model/`.
- **Covered?** No.
- **Effort.** Low: carry Smaily's body `code` on the exception, give
  `VerifiedCredentials` and the verdict a third state, and add one sentence
  (EN + ET).
- **Why before public.** Merchant confusion. The admin tells the merchant to
  re-type credentials that are fine.

### R6 — The welcome automation fires on every subscribe

- **What.** `SubscriberSaveAfter` fires the welcome automation on every
  transition to *subscribed*: a resubscribe after an unsubscribe, and also
  subscriptions created in the admin (customer edit > Newsletter), by the
  REST API or by an import. Shopify now fires the welcome only on the
  customer's own first opt-in, once per contact (PRO-1755, DECISIONS S-66,
  a record per shop and email). WooCommerce fires it only for an account the
  shopper created themselves (readme 3.11.0).
- **Evidence.** Shopify `fbe85eb`, `60fa595`, `84c7073`; WooCommerce readme
  3.11.0. Magento `Observer/SubscriberSaveAfter.php:77-81` with
  `SyncDispatcher::dispatchAutomation()` (no dedupe).
- **Covered?** No.
- **Effort.** Medium. Skipping the adminhtml area and crontab-area
  subscriptions is low effort. "Once per contact" needs a record, which is
  either a new table (a schema one-way door) or a read of
  `welcome_automation_at` from Smaily. Erkki picks the rule.
- **Why before public.** Deliverability and merchant confusion: repeat
  welcomes, and welcomes to people who never acted themselves. Whether the
  merchant's Smaily workflow already de-duplicates is unknown (hypothesis).

### R7 — The contact import ignores the contact-sync mode

- **What.** The initial contact import always sends newsletter subscribers
  (subscribed and unsubscribed). WooCommerce's import audience follows the
  mode: **all registered customers** under legitimate interest, subscribers
  under consent, **nobody** under checkout opt-in only. So a Magento
  merchant who picks "All customers" never gets the existing
  non-subscribed customers into Smaily (WooCommerce's "missing contacts"
  case). A "Checkout opt-in only" merchant imports every newsletter subscriber.
- **Evidence.** WooCommerce `docs/CONTACT_SYNC_MODES.md:49,65`, PRO-3407
  (the docs now say the import covers registered accounts). Magento
  `Model/Backfill/ContactsProcessor.php:24-28` (the subscriber collection
  only); `docs/USER_GUIDE.md:436-437`.
- **Covered?** Partly: PRO-1970 is about the estimate shown before the
  import, not the audience.
- **Effort.** Low–medium: a customer-collection branch for legitimate
  interest, and a disabled control with a reason for checkout opt-in only.
  The audience is a lawful-basis rule, so Erkki confirms it.
- **Why before public.** Data correctness and merchant confusion: the mode
  the merchant chose does not decide who is imported.

### R8 — `tags.category_defaulted` is never sent

- **What.** Contract v1.6.0: when a product has no real category, the
  sender marks the placeholder `category_path` with
  `tags.category_defaulted: "true"`, so the engine skips its slug-based
  derivations and the AI category sweep classifies the product by name.
  Magento sends the literal `"uncategorized"` with no flag. The engine
  derives species, `category_canonical` and replenishable from that
  meaningless slug.
- **Evidence.** WooCommerce `includes/Smaily/RecEngine/CatalogPayloadBuilder.php`
  (readme 3.8.1); Shopify `app/lib/engine/catalog-builder.server.ts:101`.
  Magento `Model/Engine/Payload/CatalogPayloadBuilder.php:426,446`.
- **Covered?** **PRO-1952** (Medium, backlog). It is also listed in docs/internal/BACKLOG.md.
- **Effort.** Low.
- **Why before public.** Contract drift. Both siblings send the flag, and
  recommendation quality for uncategorised products depends on it.

### R9 — Removing the module leaves secrets behind

- **What.** WooCommerce's `uninstall.php` removes every plugin setting,
  including the Campaign Intelligence connection and the opt-out registry.
  Magento has no `Setup/Uninstall.php`. `INSTALLING.md` tells the merchant
  that settings stay in `core_config_data`. Those settings include the
  encrypted Smaily password and the engine API key. Composer installs
  (the Marketplace path) can run `module:uninstall`, which would call an
  uninstall class. ZIP installs cannot.
- **Evidence.** WooCommerce `uninstall.php` (readme 3.7.0, PRO-1897).
  Magento `Setup/` (no uninstall class), `docs/INSTALLING.md:182-185`.
- **Covered?** No.
- **Effort.** Low: an uninstall class that removes `smaily_connect/*`
  config rows and module flags. Removing store data is Erkki's decision
  (CLAUDE.md "stop and ask").
- **Why before public.** Privacy and security hygiene, and Marketplace
  review expectations.

### R10 — The legacy plaintext password survives the upgrade

- **What.** The 2.8.x module stored the Smaily API password in plain text
  under `smaily/general/password`. The v3 migration encrypts a **copy**
  into the new path and leaves the legacy rows in place, so a downgrade
  keeps working. The plaintext password therefore stays in the database
  indefinitely. WooCommerce re-encrypted in place and documented that a
  rollback needs the password typed again.
- **Evidence.** WooCommerce `docs/MIGRATION.md:348-351` (PRO-2295, readme
  3.13.0). Magento `Setup/Patch/Data/MigrateLegacyConfig.php:25-27`,
  `Model/Migration/LegacyConfigMapper.php:77-79`, `docs/UPGRADING.md:28-29`.
- **Covered?** Not by name. It falls within PRO-3575's "credential and key
  storage" criterion, so it is raised there.
- **Effort.** Low: blank or delete the legacy password row after a
  successful migration, and change UPGRADING's downgrade sentence.
- **Why before public.** Security. This affects only upgraded 2.8.x stores,
  and the pilot is a clean install. Because it is a security trade-off,
  Erkki decides.

### R11 — Log Details promises evidence it never stores

- **What.** CHANGELOG and USER_GUIDE promise "the payload as sent … last
  API response" in the Log drawer. On the marketing queue, `markSent()` and
  `markFailed()` accept those values, but no production caller passes them.
  `Cron/FlushEventQueue.php:87,92` and `RetryPolicy::apply()` pass none, so the
  columns stay NULL. The WooCommerce Event Log shows the recorded exchange.
- **Evidence.** Magento `Model/Queue/EventQueue.php:184-219`,
  `Cron/FlushEventQueue.php`, `Model/Queue/RetryPolicy.php:56-61`;
  WooCommerce `includes/Smaily/Client.php` (`last_exchange`). The ingest
  queue does write a summary (`Cron/FlushIngestQueue.php:171`).
- **Covered?** **PRO-1965** and **PRO-1963** (backlog).
- **Effort.** Low–medium. Either wire the request and response summary
  through the handlers (the masking from PRO-3572 already exists), or
  narrow the public docs.
- **Why before public.** Merchant confusion and support cost: a public
  changelog promise is empty.

## Later

- **L1 — Product links in abandoned-cart reminders.** WooCommerce 3.15.0
  added `product_url_1` … `product_url_10` to the abandoned-cart payload and
  the transactional emails (PRO-3335, `c2d876c`). Magento's
  `Model/AbandonedCart/PayloadBuilder.php::productRow()` has name,
  description, image, SKU, quantity and prices, but no URL. Low effort. It
  must join the blank-slot matrix. It is a template convenience, not
  correctness. No issue exists.
- **L2 — Transactional emails.** WooCommerce 3.9.0–3.15.0: order and
  shipping confirmations through a separate Smaily account, per-language
  workflows (PRO-3187), full order details (PRO-3190), product links,
  native-email fallback and Event Log "Send again" (PRO-2324). Covered by
  **PRO-1766** (Low). High effort, a new feature.
- **L3 — Landing page widget.** WooCommerce 3.11.3 and 3.13.0 (block,
  shortcode, Elementor widget). Covered by **PRO-2455**, decided for 3.1.
- **L4 — Merge-tag reference.** WooCommerce 3.14.0 added a docs page
  listing every field each automation carries. Magento's USER_GUIDE lists the
  abandoned-cart and first-order fields inline. Low effort, docs only.
- **L5 — Smaily envelope rejections are retried.** An `HTTP 200` body with
  code 203 ("invalid data") is retried 5 times. WooCommerce makes it
  terminal only on its transactional path, so the gap is shared across
  platforms. Covered by **PRO-1962** (Low).
- **L6 — Logged-in browse identity beyond the login event.** WooCommerce
  3.8.0 links a logged-in shopper's browsing for the whole visit,
  server-side, from the login session. Magento binds only at
  `customer_login`. A visit that resumes a persistent session without
  firing that event stays anonymous until the next login (hypothesis — not
  reproduced). Low–medium effort. Gate it on R1/R2.

## Excluded — platform-specific, no Magento meaning

- **WordPress signup surfaces** (block, classic widget, shortcode, Contact
  Form 7, Elementor, the "how to add a signup form" guide): Magento uses
  its own newsletter block. The landing-page analogue is PRO-2455.
- **Action Scheduler fixes** (WooCommerce 3.13.0: stop jobs on deactivate,
  check the schedule hourly, prune finished jobs): Magento's cron belongs to
  the module and is pruned by `etc/cron_groups.xml` lifetimes.
- **Inline upgrade lock and autoloaded per-customer cache** (PRO-2434,
  PRO-2435): `setup:upgrade` runs once, and Magento's cache is not autoloaded.
- **WooCommerce Blocks checkout fixes** (first-order on the Store API, the
  opt-in script warning, the block-checkout consent marker in export and
  erasure — PRO-3426): Magento's checkout opt-in is a row in the
  abandoned-cart tracker, which `smaily:gdpr export|erase` already covers.
- **WordPress personal-data export and erasure integration**: Magento Open
  Source has no core equivalent. The CLI covers the same tables.
- **My Account registration fields (PRO-3427), staff synced as customers
  (PRO-3408)**: Magento customers and admin users are separate entities.
- **Storefront `_` global leak (3.12.1)**: Magento ships RequireJS modules
  and the Hyvä vanilla scripts.
- **Shopify webhook consent drift and repair** (PRO-3171, PRO-3173,
  PRO-3174, PRO-3176), App Proxy HMAC, web-pixel token revocation (S-63 b/c),
  optional-scope migration notice (PRO-2802), listing and landing page
  (PRO-2274, PRO-2530): no Magento counterpart.
- **Shopify customers/redact consent mirror** (PRO-3175): Magento keeps
  no per-contact consent table. `LocalEraser` matches emails case-insensitively
  (`Model/Privacy/LocalEraser.php:113,139,281`), which is the PRO-3177
  concern.
- **wordpress.org listing, Plugin Check and readme** (3.11.0, 3.13.0): the
  Magento analogue is the Marketplace path (PRO-1198).

## Already in Magento (verified in code)

- Deactivated Campaign Intelligence account remembered and every send path
  gated (PRO-2451). GDPR erasure of local queues and the abandoned-cart
  tombstone (PRO-2452, PRO-2467, PRO-2469). Abandoned-cart purchase marker
  (PRO-2453). Log "Send again", the withdrawn label and server-worded
  refusals (PRO-2454). These are the September wave.
- Browse relay refuses events while the merchant's tracking switch is off
  (`Controller/Relay/Index.php:59`; Shopify S-63 a).
- Browse events drop browser-supplied rec id and context
  (`Model/Engine/BrowseEventValidator.php:60-68`; WooCommerce 3.11.0).
- Attribution cookie names follow the tenant's setup-exchange config
  (`AttributionManager::getClientConfig()`; WooCommerce 3.11.2).
- Test connection with the stored password
  (`Controller/Adminhtml/Api/TestSmaily.php:26-29`; WooCommerce 3.11.2).
- Checkout newsletter tick in consent mode becomes a native subscriber,
  which then syncs (`Observer/OrderPlaced.php::syncContact()`; WooCommerce
  PRO-3406 analogue).
- Permanent-refusal short-circuit and Retry-After (PRO-1763, PRO-1800).
  Automation last-run markers (PRO-1761). `user_gender` / `user_phone`
  (PRO-1765). Returns on credit memos (`OrderPayloadBuilder` `returned_at`).
  Disabled workflows filtered. Terminology canon (PRO-1748). Contact-sync
  switch gates the import (PRO-1764).
- Profiling wording: the guide and the page say the opt-out stops the
  *use* in recommendations and do not claim it stops collection
  (`docs/USER_GUIDE.md:542-545`; Shopify PRO-2526).
- Contract staleness check fails on a refused token
  (`bin/check-contract-staleness.sh`; Shopify `51ec7fb`).
- i18n: EN + ET on all three platforms.

## Observations outside this audit's question

- `docs/internal/BACKLOG.md` is stale in places. It lists PRO-2451, PRO-2452, PRO-2453 and PRO-2454
  and "i18n translation files" as open, and the "browse `source:
  plugin_magento` not yet in the contract" note is wrong: the contract has
  listed the constant since v1.4.0 (§6, line 1036).
- PRO-3573 (Log masking misses encoded addresses and server-returned text)
  has an open Shopify twin (PRO-3170). Neither sibling is ahead here.
