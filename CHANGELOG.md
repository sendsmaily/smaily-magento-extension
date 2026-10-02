# Changelog

### 3.0.0 (unreleased)

The package version is currently `3.0.0-rc1` — the release-candidate cut of everything below. Nothing is published: composer still resolves 2.8.1 as the newest stable release.

Ground-up rewrite as module `Smaily_Connect`, targeting feature parity with the Smaily Connect plugins for WooCommerce and Shopify. Upgrading from 2.8.x is seamless: the composer package name is unchanged and all settings (including the previously plaintext API password, now encrypted) migrate automatically during `setup:upgrade`.

**New features**

- Contact-sync lawful-basis modes: subscribers only (consent, default), all customers (legitimate interest), checkout opt-in only.
- Two-way consent sync: unsubscribes/resubscribes in Smaily mirror back onto Magento newsletter subscribers (action-log delta polling).
- Welcome and first-order automations alongside the abandoned cart automation; per-language workflow routing for multilingual stores (store view = language).
- Every store-event automation records its own last run on the Smaily contact — `welcome_automation_at`, `first_order_automation_at`, `abandoned_cart_automation_at` (`YYYY-MM-DD HH:MM:SS` UTC, rewritten on every run) — so you can segment on "has received the welcome letter" or "got the abandoned-cart reminder more than 30 days ago". The same field names are used by the WooCommerce and Shopify plugins, so segments transfer between stores.
- A purchase stops the abandoned-cart follow-ups: when a shopper the extension tracked as having abandoned a cart places an order, their Smaily contact gets `abandoned_cart_purchased_at` (`YYYY-MM-DD HH:MM:SS` UTC, the same format and field name the WooCommerce plugin uses), so a workflow can exit while `abandoned_cart_purchased_at` is later than `abandoned_cart_automation_at`. A reminder that had not gone out yet is withdrawn instead of sent. An ordinary purchase writes nothing and creates no contact.
- Contacts can carry the customer's phone number — the default billing address telephone, synced as `user_phone` (the same field name the WooCommerce plugin uses) when you tick Phone under Synchronized Fields. A customer without a number sends nothing at all, so a phone already in Smaily is never wiped.
- Full multilingual admin UI: when store views speak more than one language, the Connection panel (initial setup step 1 / Settings > Connection) offers four routing-mode choice cards; "Per-language Smaily accounts" mode manages one credential block per language (each with its own Test Connection) plus a default-fallback account picker, and both per-language modes add a per-language workflow mapping editor to the Automations panel with live per-account workflow lists and a default-fallback row per trigger. All sections adapt live to the selected mode — nothing saves until you say so. A mapped workflow always fires through the Smaily account its mapping row names (fallback rows included), matching the WooCommerce plugin's routing.
- Checkout newsletter opt-in checkbox (guests and customers, double opt-in respected).
- A native admin home under **Marketing > Smaily Connect** with four pages: **Dashboard** (one-sentence health verdict, connection status, truthful operational counters from the local queues, recent activity), a guided five-step **Initial setup** (fresh installs land there automatically until setup is completed), a tabbed **Settings** page (Connection / Contacts / Automations / Intelligence / RSS — the initial setup's steps as always-available, deep-linkable tabs with instant per-tab AJAX saves and live field reactivity) and one unified **Log**. These pages are the sole configuration surface — there is no separate Stores > Configuration entry.
- Multi-website support: installs with more than one Magento website get an explicit website selector on Settings (and a website-picker step in the initial setup), so each website keeps its own Smaily connection, contact sync and automation settings. Single-website installs are unaffected.
- Durable event queues with retries/backoff, one unified admin Log over both delivery queues (a Source column tells Smaily and Campaign Intelligence rows apart) with cross-queue mass retry, and health notices when deliveries keep failing.
- Per-row **Details** drill-down in the Log (slide-out panel): the payload as sent, attempt count, next automatic retry time (or an honest "will not retry on its own"), last error and last API response — secrets are never shown and email addresses are masked. A failed-deliveries banner above the grid and the dashboard's failed tile deep-link to the Log pre-filtered to failed rows. A failed row can be sent again from the Log: a new attempt is queued, the failed row is kept as the record of what went wrong, and the new row notes which row it repeats, who sent it and when. Where sending again would reach the shopper twice or reach nobody — a reminder withdrawn because the shopper bought, a later message of the same kind already delivered, a contact erased under Art. 17 — there is no button, the row says why in one sentence, and the mass retry skips it and reports how many it skipped. A withdrawn reminder is labelled *Withdrawn* rather than sent or failed.
- Historical import (backfill) of contacts, catalog, customers and orders — one click with live progress on the Settings page (Contacts / Intelligence tabs) or CLI. A running import can be cancelled (the worker stops cleanly at the next page boundary; starting again begins a fresh run), and the last outcome + timestamp persist on the panel with honest copy: "Done, X of Y synced", "… N failed" with a Log deep-link (items still pending retry are not counted as failed), "Stopped before an error", or "Cancelled". The contact import obeys that website's contact-sync switch like every other outbound contact path: with the switch off the import control is disabled and explains why, and an import started any other way sends nothing.
- After a major version upgrade a one-time admin notification suggests reviewing the settings or re-running the initial setup — settings are never changed or blocked.
- Campaign Intelligence integration: catalog/customer/order/browse ingest, recommendation attribution, identity merge, engine-run automations admin, GDPR export/erase CLI and a shopper personalization opt-out page.
- A data-subject erasure now reaches the store's own tables, not just Campaign Intelligence: `bin/magento smaily:gdpr erase <email> --force` deletes every queued message that could still be sent to that contact and anonymises the ones already sent (they stay in the Log as your record, showing `[erased]` in place of the address, the payload and the response), anonymises the contact's abandoned-cart record and keeps it (the address goes, the record stays so the cart is never picked up and mailed again), and prints a count per table. The local part runs first, so an engine outage never blocks it. `smaily:gdpr export` lists the same rows.
- A deactivated Campaign Intelligence account is remembered and acted on instead of retried: every send path (ingest flush, engine-bound historical imports, the browse relay) stops, queued rows are left untouched and go out in order once the account is active again, and Settings > Intelligence, the Dashboard verdict and the admin notification say plainly that the account is not active on the Smaily side — with a **Check again** button that resumes everything the moment it is. Smaily email sending and the contact import are unaffected.
- The Smaily connection status tells the truth: the Dashboard and Settings > Connection say *Connected* only when Smaily accepted the saved credentials at the last check — Test Connection, or any save of the connection, which now asks Smaily once without ever blocking the save. Changed credentials stay *Not connected* until they are checked, and a later refusal by Smaily (HTTP 401/403, also on a queued delivery) turns the status back to *Not connected*. The Dashboard has a **Not connected** verdict with an **Open Connection settings** button; it outranks failure counts, which a refused connection explains. No page load calls Smaily, and the credentials are remembered only as keyed hashes.
- RSS feed improvements: category **ID** filter, limit/sort/order parameters, cache headers, enable/disable toggle [[#48](https://github.com/sendsmaily/smaily-magento-extension/issues/48), [#49](https://github.com/sendsmaily/smaily-magento-extension/issues/49), [#50](https://github.com/sendsmaily/smaily-magento-extension/issues/50), [#72](https://github.com/sendsmaily/smaily-magento-extension/issues/72)]
- Feed URL Builder in the Product RSS Feed configuration group: pick category/limit/sorting and copy the ready feed URL with one click; the initial setup's Overview step links to it.
- In-admin documentation: the initial setup's Overview step and the post-install notice link to the full user guide.
- A dedicated log file, `var/log/smaily_connect.log`, that records errors only by default [[#113](https://github.com/sendsmaily/smaily-magento-extension/issues/113)]. There is no admin control for the log level: a developer raises it for troubleshooting with `bin/magento config:set smaily_connect/logging/verbosity info|debug` and sets it back to `error` afterwards, because the detailed levels write more about contacts to a file on the server (see the user guide).
- Translations: full English (`i18n/en_US.csv`) and Estonian (`i18n/et_EE.csv`) translation packs covering the admin (initial setup, configuration, grids) and the storefront (checkout opt-in, personalization page). API and engine error messages surfaced in the admin are translated too, framed in sentences that stay understandable even when the remote service's own message is technical.

**Under the hood**

- New module name `Smaily_Connect` (namespace `Smaily\Connect`); composer package name unchanged.
- Declared PHP (8.1–8.4) and Magento (2.4.4+) requirements in composer.json [[#18](https://github.com/sendsmaily/smaily-magento-extension/issues/18)]
- No more columns on the core `quote` table; legacy `reminder_date`/`is_sent` columns and the unused `smaily_customer_sync` table are cleaned up on upgrade.
- Store-timezone-safe scheduling (the hardcoded Europe/Tallinn timezone is gone); Guzzle-based API clients with timeouts and typed errors.
- Legacy custom captcha replaced by Magento's native reCAPTCHA module (admin notice on upgrade).
- Unit tests, phpcs/phpstan static analysis and CI added [[#51](https://github.com/sendsmaily/smaily-magento-extension/issues/51)]
- Integration test suite against a real MySQL (queue retry/backoff/claim semantics, the 2.8.x → v3 settings and schema migration, queue cron flows with stubbed HTTP transports), run in CI with a MySQL 8.4 service — see [TESTING.md](https://github.com/erkkimarkus/magento-connect/blob/v3/TESTING.md).
- Ships the Campaign Intelligence engine wire contract (`docs/RECENGINE_API_CONTRACT.md`, v1.8.1, byte-synced across Smaily connect repositories): order amounts gross/tax-inclusive (`row_total_incl_tax` / `grand_total`), browse events tagged `source: plugin_magento`, and the browse beacon degrades to sender-side anonymous mode (identity hint omitted, events keep flowing) when cookie consent is absent.
- Stock changes reach Campaign Intelligence, not just product edits: a shipment that sells the last unit, a credit memo that returns it, an Advanced Inventory or Sources edit and the stock REST endpoints all re-sync the product, on both Multi-Source Inventory and legacy-inventory installs. A nightly full catalog re-sync (03:40 store time) reconciles what no event can see, such as a CSV import writing the catalog tables directly — so a product's availability is at most a day stale even then, and normally a minute or two.
- Catalog rows carry the platform parent product id as `tags.product_id` (a configurable child resolves to its parent's entity id). A product hard-delete soft-removes the whole product engine-side via `POST /api/v1/ingest/catalog/remove` (contract §3b); a configurable child's deletion keeps the per-SKU out-of-stock path, and disabling a product remains a soft out-of-stock update.
- Automation workflow dropdowns list only workflows the Smaily API can actually trigger (`GET workflows.php?trigger_type=form_submitted`, the same listing the WooCommerce plugin uses). Listing every ACTIVE automation instead (`GET autoresponder.php`) offered workflows whose trigger type is not "form submitted" — selecting one made every automation fail with Smaily's misleading error 221 "Invalid autoresponder ID". Disabled workflows are excluded too: enrolling one returns OK but silently sends nothing. Verified against a live Smaily account.
- Contact and abandoned-cart payloads now carry the real store group name in `store_group` (a wrong accessor left it always empty).
- Saving the Campaign Intelligence automations no longer silently drops a workflow binding whose Smaily workflow has since been deleted (or when the workflow list fails to load): the missing workflow is kept and shown as "Workflow #N (not in your Smaily list — kept)" rather than reset to "Not Selected". Deliberately clearing a workflow that is still in the list works as before.
- Background jobs (event queue flush, ingest queue flush, backfill tick, abandoned cart, contact reconcile, health check, queue janitor) now actually run: the module's custom `smaily_connect` cron group was missing its `etc/cron_groups.xml` definition, so Magento's cron scheduler never picked up a cadence for it and none of the 7 jobs was ever scheduled, on any install. Fixed by adding the missing definition file.
- The abandoned-cart job now completes: the guest-cart widening had left an ambiguous column reference in its quote query, so MySQL rejected the whole SELECT and the job failed on every run — no reminder was ever sent, on any install. Fixed by qualifying the filter columns.
- A delivery Smaily refuses outright — revoked credentials, a deleted workflow, a rejected address — is now marked failed on the first refusal, with the refusal in the last error, instead of being re-sent five times over six hours: the Log, the failed-deliveries banner and the dashboard tile report it straight away. When Smaily asks the store to slow down (HTTP 429) the delivery waits exactly as long as Smaily asked. Server errors and network failures keep the existing 1 min → 6 h ladder. Same classification the sibling Smaily plugins use.
- A network failure on a call to Smaily or Campaign Intelligence no longer writes a contact's email address anywhere. The failure text used to end with the full request address — for the consent lookup that included the email in its query string, for the Campaign Intelligence customer calls the email in the path — and that text reached `var/log/smaily_connect.log` (even at the default errors-only level), a queued delivery's last error and the Log. The text now keeps the server and path, drops the query string, and shows `{email}` where the address was in the path.
- A malformed recommendation id no longer costs Campaign Intelligence the order. The engine refuses a whole order over a `smaily_rec_id` that is not a well-formed UUID; the storefront now stores the id from a campaign link (`smaily_rec`, or the `utm_content` fallback) only when it is well-formed, and the order sent to the engine leaves out a malformed stored id, so the order arrives without that attribution instead of not at all.
- Contract staleness guard in CI: a dedicated daily "Contract staleness" workflow (`bin/check-contract-staleness.sh`) fails when the vendored `docs/RECENGINE_API_CONTRACT.md` is no longer byte-identical with the engine repo's main branch.
- Hyvä theme compatibility module (`compat/hyva/`, module `Hyva_SmailyConnect`, to be published as the separate package `smaily/module-connect-hyva`): framework-free browse tracker and attribution scripts (no RequireJS/jQuery, no inline executable script — nothing to whitelist even under strict CSP), a Tailwind-styled personalization page and Tailwind-build registration via `hyva:config:generate`. Verified end-to-end on Hyvä 1.5.2 — default theme and the strict-CSP variant (`Hyva/default-csp`, storefront CSP enforced without `unsafe-inline`), with a Luma store view as the regression control; the free Hyvä tier's Luma-fallback checkout keeps the checkout opt-in working unchanged. Audit and the executed verification matrix in [docs/HYVA_SUPPORT.md](https://github.com/erkkimarkus/magento-connect/blob/v3/docs/HYVA_SUPPORT.md); excluded from the release ZIP.
- The marketing event queue carries an index on `(entity_id, event_type, status)`: the lookup that withdraws a shopper's still-pending abandoned-cart reminder runs on every order placed, and on a busy store's queue it was a full table scan.
- The release ZIP is assembled by one script (`bin/build-release-zip.sh`) that both the release workflow and the new packaging check call, and every push now builds the package and verifies it (`bin/verify-release-zip.sh`): required module files present, development material and the separately published Hyvä companion absent, the archived version equal to the repository's, `php -l` clean on every shipped file, and a SHA-256 build hash written beside the artifact. Published releases attach that `smaily-connect-magento2.zip.sha256` file next to the ZIP, so a manual installer can verify the archive with `sha256sum -c` before extracting it.
- Package manifest completeness: `magento/module-catalog-inventory` and `magento/module-ui` are used by the module but were never declared in composer.json — an install that had them removed would have failed at runtime rather than at composer time.
- Module manifest completeness: `etc/module.xml` now sequences `Magento_CatalogInventory` and `Magento_Ui` alongside the other required Magento modules, so Magento loads the module after everything it depends on. The optional Multi-Source Inventory packages the Campaign Intelligence stock hooks plug into are listed under composer `suggest` — they are wired through `etc/di.xml` only, and an install without MSI works unchanged.

**Behavior changes**

- Contact sync frequency presets are gone: v3 syncs in near-real-time via observers + a 15-minute consent reconcile.
- Gender now reaches Smaily under the field name `user_gender` (2.8.x sent `gender`), the name Smaily's WooCommerce plugin uses, so one shopper syncing from two stores lands in one field. Your tick in Synchronized Fields migrates automatically; Smaily segments and templates that reference `gender` need repointing to `user_gender` once.
- The RSS feed lists catalog-visible products only; configurable variants resolve to their parent.

### 2.8.1

Fixes an issue with cron scheduling using wrong interval for daily customer synchronization.

### 2.8.0

> Notice! This version updates the price values in abandoned cart emails and RSS feed items to include taxes. These prices now match what customers see in the storefront. For B2B (business-to-business) stores, where tax-exclusive pricing may be expected, this behavior might not be suitable.

- Abandoned cart `product_price` and `product_base_price` now also include taxes.
- RSS-feed now shows prices including taxes.
- RSS-feed uses parent product URL-s for configurable products that are not visible individually.

### 2.7.7

- Adds `"is_abandoned_cart" = "true"` field to abandoned cart automation payload
- Does not opt-in unsubscribed customers who have received abandoned cart email

### 2.7.6

- fix: RSS feed rendering with missing description value [[#118](https://github.com/sendsmaily/smaily-magento-extension/pull/118)]

### 2.7.5

- fix: Items placement in RSS feed structure [[#114](https://github.com/sendsmaily/smaily-magento-extension/pull/114)]

### 2.7.4

- Fixes an issue where abandoned cart synchronization can fail when unknown payload field is encountered.[[#111](https://github.com/sendsmaily/smaily-magento-extension/pull/111)]

### 2.7.3

- Fixes non-existing array key warning on subscribers synchronization [[#108](https://github.com/sendsmaily/smaily-magento-extension/pull/108)] (thanks @raulikesvatera)

### 2.7.2

- PHP 8.2 compatibility [[#103](https://github.com/sendsmaily/smaily-magento-extension/pull/103)]

### 2.7.1

- Skip abandoned carts receiving "Invalid data submitted" (code: 203) response - [[#99](https://github.com/sendsmaily/smaily-magento-extension/pull/99)]

### 2.7.0

- Add store, store group and website to abandoned cart payload - [[#96](https://github.com/sendsmaily/smaily-magento-extension/pull/96)]

### 2.6.0

- Compare subscriber status change timestamp on newsletter subscriber sync [[#91](https://github.com/sendsmaily/smaily-magento-extension/pull/91)]
- Fix newsletter subscribers sync unsubscribed status value [[#92](https://github.com/sendsmaily/smaily-magento-extension/pull/92)]

### 2.5.0

- Add product image URL to abandoned cart data payload [[#88](https://github.com/sendsmaily/smaily-magento-extension/pull/88)]

### 2.4.0

- Include more context in CRON job logs [[#82](https://github.com/sendsmaily/smaily-magento-extension/pull/82)]
- Fix CRON job logging duplicate lines [[#83](https://github.com/sendsmaily/smaily-magento-extension/pull/83)]
- Optimize abandoned cart CRON job by excluding sent carts [[#84](https://github.com/sendsmaily/smaily-magento-extension/pull/84)]

### 2.3.1

- Test for Magento 2.4.4 compatibility - [[#78](https://github.com/sendsmaily/smaily-magento-extension/pull/78)]
- Convert module schema and data setup to declarative schema - [[#77](https://github.com/sendsmaily/smaily-magento-extension/pull/77)]

### 2.3.0

- Newsletter Subscribers synchronization tracking per website - [[#73](https://github.com/sendsmaily/smaily-magento-extension/pull/73)]
- Make last synchronization datetime configurable in module settings - [[#73](https://github.com/sendsmaily/smaily-magento-extension/pull/73)]

### 2.2.0

- Include store group and website in opt-in form and synchronized data [[#67](https://github.com/sendsmaily/smaily-magento-extension/pull/67)]
- Add automation workflow selection to Newsletter Subscriber settings [[#68](https://github.com/sendsmaily/smaily-magento-extension/pull/68)]

### 2.1.0

- Magento 2.4 compatibility [[#63](https://github.com/sendsmaily/smaily-magento-extension/pull/63)]

### 2.0.0

This is a complete rework of the module. The aim was to make the module configurable by website, i.e. abandoned cart, newsletter subscribers synchronization, opt-in form and Smaily API could be configured for each website. Only reasonable solution was to rebuild the module from ground up, because most (if not all) of the functionality was "Default configuration"-centric.

- Improves efficiency of Newsletter Subscribers and Abandoned Cart CRON jobs [[#36](https://github.com/sendsmaily/smaily-magento-extension/issues/36)]
- Reduces bloatiness of data Helper [[#37](https://github.com/sendsmaily/smaily-magento-extension/issues/37)]
- Fixes double CAPTCHA input fields [[#46](https://github.com/sendsmaily/smaily-magento-extension/issues/46)]

### 1.2.0

- Align synchronization customer first and last name with abandoned cart [[#52](https://github.com/sendsmaily/smaily-magento-extension/pull/52)]

### 1.1.0

- Add new fields `first_name` and `last_name` for abandoned cart export
- Changes `product_qty` field to `product_quantity` to unify template variables across integrations

### 1.0.2

- Fix RSS-feed not rendering with special characters

### 1.0.1

- Fix PHP 5.6 compilation issues

### 1.0.0

- Make using CAPTCHA optional for better integration with pop-up forms

### 0.9.3

- Add Magento CAPTCHA and Google reCAPTCHA option for newsletter sign-up form

### 0.9.2

- Fix compilation issues

### 0.9.1

- Subdomain is now parsed from full URL
- Newsletter signup form uses opt-in autoresponder workflow
- Updated cron frequency values
- Updated abandoned cart timing values
- Customer synchronization is now more efficient as it uses data batching
- Customer unsubscribed status is also updated in store's database
- Uninstall cleans up created tables and columns
- Removed custom newsletter and email template blocks
- Removed subscriber observer as synchronization provides same functionality
- Fixed broken links in settings from

### 0.9.0

- This is the first public release
