# RFC: Multi-Website Support

> **Status:** draft, pending review. References PRO-1456 (this RFC) and
> PRO-1459 (engine-side per-tenant provisioning/billing confirmation, an
> external dependency for Phase 4 below).

## Decision

One Magento **website** = one Campaign Intelligence (engine) tenant, with its
own Smaily marketing-account binding. A multi-website install runs several
engine tenants from one module install — each website gets its own setup
exchange, backfill, and ingest stream.

Inside a website, today's multilingual mode choice is unchanged: all store
views can share one Smaily account (language attached as a parameter) or get
one Smaily account per language. The only change is the axis the account
resolver keys on — **website × language**, not (as today) language alone
across the whole installation.

Store groups are not a binding unit. The tenant is per website; a website's
canonical store view — the one scope its engine ingest and Smaily-account
resolution reduce to when a single representative view is needed — is its
default store's default store view.

Single-website installs behave exactly as today. Migration maps the existing
single tenant and Smaily credentials onto the default website. This also
restores the "one Smaily account per website" capability the legacy 2.8.x
extension had and v3 currently does not.

This document is the **how**; the direction above is settled. It walks each
affected subsystem current → target → migration, then lays out an
independently shippable phase plan.

## Terminology

- **Website** — the Magento scope tree's top level (`Magento\Store\Model\Website`).
  The tenant-binding unit for both the engine and (going forward) the Smaily
  marketing account.
- **Store / store view** — Magento's finer scopes under a website. Store
  groups are not a binding unit in this design; store views remain the unit
  multilingual mode already operates on, nested inside a website.
- **Tenant** — one Campaign Intelligence account, obtained via one
  `POST /api/setup/exchange` (RECENGINE_API_CONTRACT.md §Endpoints), holding
  its own API key, endpoints map, and config.
- **Account (marketing)** — one Smaily API credential set (subdomain +
  username + password), addressed today by `account_key` (`'default'` or a
  language code) in `Model/Multilingual/AccountResolver`.

## 1. Config/settings scoping

**Current:** `Model/Config.php` getters are fully website/store-scope-aware
(`ScopeConfigInterface::getValue()` with an optional `$storeId`), but writes
from the module's own Setup Wizard / Settings page
(`Model/Adminhtml/WizardStepSaver.php`) land almost entirely at **default**
scope — `configWriter->save($path, $value)` with no scope argument. Only
multilingual mode A's per-language credentials write at store-view scope.
This read/write asymmetry is why the PRO-1274 "Overridden for X" banner
exists at all.

**Target:** writes gain an explicit website dimension. `WizardStepSaver`'s
save methods take the target `$websiteId` (defaulting to the installation's
only website, unchanged behaviour for single-website installs) and write via
`WriterInterface::save($path, $value, ScopeInterface::SCOPE_WEBSITES, $websiteId)`
for every field that is meant to bind per website (connection credentials,
subscriber sync toggles, automation toggles, the new Intelligence fields —
see §3). Multilingual mode A's per-store-view writes stay at store-view
scope, but only across the **target website's own store views** — see §2.

**Migration:** no destructive step is required for a genuinely single-website
install — Magento's scope resolver already falls back from website scope to
default scope when no website-specific row exists, so an un-migrated
default-scope value already reads correctly as "the default website's value."
For UI clarity (so the value is visible and editable as a website-scoped row
rather than an invisible fallback), a `Setup/Patch/Data` patch explicitly
copies each affected default-scope value into an explicit row at the
installation's default website scope, once, on upgrade. No config path is
renamed (respects the existing 2.8.x path-migration constraint,
`docs/ARCHITECTURE.md` "Layout" / `Model/Migration`).

## 2. Smaily account binding: website × language resolver

**Current:** the binding unit is language, full stop —
`Model/Multilingual/AccountResolver::detectedLanguages()` and
`storeIdsForAccountKey()` both iterate `$storeManager->getStores()`, every
store view across every website, and bucket by language code alone
(`Model/Multilingual/AccountResolver.php:37-86`). Two websites that both have
an `en` store view are, today, the SAME account key — mode A's save writes
identical credentials to both, and there is no way to give them different
Smaily accounts.

**Target:** both methods gain a `$websiteId` parameter and scope their store
iteration to `$storeManager->getWebsite($websiteId)->getStoreIds()` instead
of every store in the installation:

```php
public function detectedLanguages(int $websiteId): array
public function storeIdsForAccountKey(string $accountKey, int $websiteId): array
```

`ViewModel/Adminhtml/WizardData::isMultilingual()` and the wizard's
`getStoreTotals()` become website-scoped the same way, so a multi-website
merchant sees per-website language counts and totals during setup instead of
one undifferentiated installation-wide number.

**Wizard flow (which website am I configuring):** for the common
single-website case, nothing changes — the wizard proceeds exactly as today,
implicitly targeting the installation's one website. When 2+ websites exist,
the wizard gains a website-chooser step before step 1 (rendered only in that
case), and `Model\Adminhtml\SetupGuard`'s `setup_completed` gate becomes
per-website so each website's onboarding is tracked independently.

**Settings page website context — recommendation: explicit selector, not
the native Magento store-switcher.** The Settings page is not Magento's
native `system_config` edit controller — it is a custom
`Magento\Backend\Block\Template` page (`Controller/Adminhtml/Settings/Index.php`)
that already has its own explicit, deep-linkable `?tab=` scheme
(`docs/ARCHITECTURE.md` "Admin UI"). Bolting on Magento's native store-switcher
chrome (designed for `system_config`'s own controller/form machinery) would be
a disproportionate rebuild for a page that already solves an analogous
navigation problem its own way. A website dropdown next to the tab strip,
persisted as `?website=<id>` and shown only when 2+ websites exist, is the
smaller, consistent change.

**Migration:** the single-website case needs no migration (§1). For an
install already running 2+ websites under today's language-only mode A, the
existing per-store-view credential rows are re-interpreted as belonging to
whichever website that store view is in — no data moves, since mode A already
wrote at store-view scope; only the *resolution* code (§ above) changes from
"every store view of this language, any website" to "this website's store
views of this language."

## 3. Engine tenant per website

**Current:** one engine tenant per **installation**, by explicit design —
`Model/Engine/Settings.php`'s class docblock says exactly this ("stored at
default scope, one engine tenant per Magento installation"). Every getter
calls `ScopeConfigInterface::getValue()` with no scope argument at all — the
class has no `$storeId`/`$websiteId` parameter anywhere, unlike `Model/Config`.
`etc/adminhtml/system.xml`'s `intelligence` group is
`showInWebsite="0" showInStore="0"` on every field, so native config can't
even create an override. `BACKLOG.md` records this as a deliberate,
already-shipped decision ("per-website engine tenants are out of scope"),
which this RFC now reverses.

**Target:**

- `Model/Engine/Settings` gains a `$websiteId` parameter on every getter and
  on `storeExchange()`/`clear()`, mirroring `Model/Config`'s existing pattern.
  Config path shape is **unchanged** — same
  `smaily_connect/intelligence/*` paths, now written and read at
  `ScopeInterface::SCOPE_WEBSITES` instead of the global default (a scope
  change, not a path rename).
- `etc/adminhtml/system.xml`'s `intelligence` group changes to
  `showInWebsite="1" showInStore="0"` — website is the finest scope; there is
  deliberately no store-view engine variant (§ Out of scope, below).
- The encrypted API key is stored per website (one `core_config_data` row per
  connected website, same encryption as today).
- **Setup exchange per website:** the Settings > Intelligence tab's paste-a-
  setup-URL flow posts with the target website's id in context; the
  controller resolves `$websiteId` from the page's website selector (§2) and
  calls `Settings::storeExchange($response, $websiteId)`. Each website is
  onboarded with its **own** setup token — Erkki (or the engine admin UI)
  issues one setup URL per tenant, one tenant per website
  (RECENGINE_API_CONTRACT.md §1 `POST /api/setup/exchange`).
- `Model/Engine/Client` stops being an implicitly-singleton client bound to
  "the" tenant; call sites resolve a client for a specific website (a small
  factory wrapping the existing `Client` constructor with that website's
  `Settings`-resolved config, not a redesign of `Client` itself).
- **Health check per tenant:** `Cron/HealthCheck.php` is currently single-pass
  (no store/website iteration, confirmed by grep — zero references to
  `getWebsites`/`storeManager`). It becomes a website-iterating cron: for
  each website with `Settings::isConnected($websiteId)` true, run the same
  failed-rows / engine-down check scoped to that website's queue rows and
  post a per-website notice.

**Migration:** the existing single tenant (API key, tenant id/name, endpoints
map, config, `issued_at`) is copied by the same `Setup/Patch/Data` patch as
§1 onto the installation's default website scope. No re-exchange is required
— the existing tenant keeps working for the default website unchanged.
Additional websites start unconnected and go through their own setup exchange
when the merchant chooses to onboard them.

## 4. Queues / backfill / cron — schema and iteration

Checked every table in `etc/db_schema.xml` against what a per-website tenant
needs:

| Table | Website dimension today | Change needed |
|---|---|---|
| `smaily_event_queue` | `website_id` column already present, already used for routing | none |
| `smaily_automation_mapping` | `website_id` column already present, `Router` already resolves it | none (UI-only, §6 below) |
| `smaily_backfill_job` | `website_id` column already present; contacts backfill already starts one job per website (`Controller/Adminhtml/Api/BackfillState.php`) | none — engine backfill jobs (catalog/customers/orders) currently hard-code `websiteId = 0` at the **call site**, not the schema; change the call site to loop over websites the same way contacts already does |
| `smaily_abandoned_cart` | `store_id` column, cron already iterates websites and filters by that website's store ids | none |
| `smaily_order_attribution` | none, but resolves via `order_id` → the order's own `store_id`/website | none |
| `smaily_ingest_queue` | only a nullable `store_id` (i18n context, not a tenant key); no `website_id` at all | **new `website_id` column — see below** |

**One-way door — schema migration, needs its own sign-off before build:**
`smaily_ingest_queue` gains a `website_id` (`smallint unsigned`, matching the
other tables' column shape) so `Cron/FlushIngestQueue` knows which website's
tenant (API key, endpoints, base URL) to send a given queued row through —
today there is exactly one engine tenant, so no such column was ever needed.
Every enqueue site stamps it at write time: `Observer/Engine/ProductSaveAfter`
/`ProductDeleteBefore`, `Model/Backfill/EngineCatalogProcessor`,
`Model/Engine/Payload/CustomerPayloadBuilder`'s observer, `Observer/Engine/OrderSaveAfter`,
and the browse relay (`Controller/Relay/Index`). Existing queued rows at
migration time (any row not yet flushed when the patch runs) have no natural
website — the patch backfills them to the default website's tenant, the same
one they were always going to be sent to under the current one-tenant design,
so no in-flight row's destination silently changes. `Cron/FlushIngestQueue`
groups its per-domain batches by `website_id` before calling the engine
client, one batch per (domain, website) pair.

**Crons that stay single-pass** (`FlushEventQueue`, `QueueJanitor`,
`BackfillTick`): unchanged — they drain rows that already carry their routing
scope (`website_id` on the event queue, now also on the ingest queue), no new
iteration needed. **Crons that already iterate websites** (`AbandonedCart`,
`ContactReconcile`): unchanged in iteration shape; §6 covers a reconcile
completeness fix within that existing loop.

## 5. Ingest payload scoping (catalog / orders / customers / browse)

**Current:** every ingest path resolves through ONE canonical store —
`CatalogPayloadBuilder::canonicalStoreId()`, the default store view of the
**installation's** default website. Since PRO-1458 the catalog has one
exception: a product NOT assigned to that website is priced and linked at
the default store view of the first website it is assigned to
(`storeIdForProduct()`) — still one payload per product, and still the
canonical store's `currency` on it. Orders and customers carry no explicit
website scoping at all. The wire contract has no currency field, so
today's single-tenant design papers over this by construction (one tenant,
one base currency, always the default website's).

**Target:** `canonicalStoreId()` becomes `canonicalStoreId(int $websiteId)`
— that website's own default store's default store view (the RFC's
canonical-store-view rule), not the installation's. Every ingest path is
re-scoped per the entity's own website(s):

- **Catalog:** a product assigned to N websites enqueues one ingest row per
  website it belongs to (name/description/URL were already built per
  language across a product's assigned websites —
  `storesByLanguage()` filtering to `$product->getWebsiteIds()`; price and
  URL follow one website per product since PRO-1458, and now resolve per
  that row's own website's canonical store). Backfill
  (`CatalogProductLoader::loadPage()`) and the live path
  (`ProductSaveAfter`/`ProductDeleteBefore`) both route through this.
- **Orders:** `OrderPayloadBuilder` resolves the order's own `store_id` →
  website and enqueues to that website's tenant. Since one tenant now means
  one website means (by this design) one base currency, order amounts are no
  longer currency-ambiguous across websites on different base currencies —
  this closes the currency-blindness edge PRO-1352/PRO-1458 flagged, without
  needing a currency field on the wire.
- **Customers:** `CustomerPayloadBuilder` resolves the customer's own
  `store_id` → website the same way; a customer who has interacted with more
  than one website is ingested into each of those websites' tenants
  independently (each tenant only ever sees that website's view of the
  customer — no cross-website merge is implied by this RFC).
- **Browse:** the relay stamps `website_id` alongside the existing
  server-side `source: plugin_magento` stamp, resolved from the request's
  store context.
- **Engine backfill jobs** (catalog/customers/orders) start one job per
  website (§4), matching contacts' existing per-website start loop.

**Additive wire-contract note (not assumed):** an optional `currency` field on
order/catalog payloads is a possible engine-side nice-to-have once
per-website ingest exists (cross-team, tracked as PRO-1459's sibling), but
this design does not depend on it — one tenant, one base currency already
disambiguates currency without any contract change.

## 6. Related open defects folded in

- **PRO-1457 (consent reconcile must cover all resolved accounts):**
  `Cron/ContactReconcile.php` already iterates websites correctly, but within
  each website it only ever polls through that website's **default store's**
  account — a website with several mode-A per-language accounts only gets one
  of them reconciled. Fixed in **Phase 3** below: once §2's website-scoped
  `AccountResolver` exists, the cron loops every distinct account within the
  website (its per-language accounts, not just the default store's), the same
  shape the contacts backfill already uses.
- **Per-website automation mapping UI:** `smaily_automation_mapping` already
  has the `website_id` column and `Router` already resolves it
  (`Model/Automation/Router.php:47-84`); the only gap is that the admin save
  path (`Model/Adminhtml/WizardStepSaver::saveAutomations()`) always calls
  `MappingSaver::save()` with a hard-coded `websiteId = 0`. Fixed in the same
  Phase 3 — pass the real target website id instead.

## 7. Migration summary

- **From today's v3 single-tenant installs:** one `Setup/Patch/Data` patch
  (Phase 4) copies the existing default-scope Connection/Subscribers/
  Automations/Intelligence config and the existing engine tenant onto the
  installation's default website's explicit scope. No re-authentication, no
  re-exchange, no config path renamed.
- **From 2.8.x:** unchanged from what's already shipped
  (`Model/Migration`, `docs/ARCHITECTURE.md` "2.8.x migration") — legacy
  per-**website** credentials already map cleanly onto this design's website
  binding unit (2.8.x had no store-view granularity either, so there is no
  precision to lose). The existing constraint that a value-migration step
  must never rename a config path (`STATUS.md`, PRO-1286/1401 entries) is
  respected throughout this RFC — every change above is a scope change, never
  a path rename.
- **In-flight queue rows** at the `smaily_ingest_queue` schema migration
  (§4): backfilled to the default website, matching where they were always
  headed under the current one-tenant design.

## 8. Phasing

Each phase is independently shippable and independently verifiable (unit +
integration + a sandbox Playwright pass); a later phase never has to unwind
an earlier one. Effort is LOW throughout — every phase reuses existing
schema/plumbing rather than inventing new mechanisms, per §4's table.

| Phase | Scope | Effort | Depends on |
|---|---|---|---|
| **1** | Website-scoped config **writes** (§1) + `AccountResolver`/`LanguageResolver` gain a `$websiteId` dimension (§2, resolver only — no UI yet) | LOW | — |
| **2** | Multi-website UI: wizard website-chooser step, Settings page `?website=` selector, per-website `SetupGuard` (§2) | LOW | Phase 1 |
| **3** | Turn on already-built per-website plumbing: automation mapping UI (§6), consent-reconcile per-account loop / PRO-1457 (§6) | LOW | Phase 1 |
| **4** | Engine tenant per website: `Engine\Settings` website dimension, `system.xml` scope, setup exchange per website, per-tenant health check (§3) | LOW, **gated** | Phase 2; **external gate: PRO-1459 engine-side confirmation** |
| **5** | Ingest payload + queue website dimension: `smaily_ingest_queue.website_id` schema migration (one-way door, own sign-off), per-website catalog/order/customer ingest, per-website engine backfill start (§4, §5) | LOW, but starts with a schema-migration sign-off checkpoint | Phase 4 |

Verification per phase: unit tests for the changed resolver/saver/builder
logic, `phpunit.integration.xml.dist` for anything touching real
`core_config_data` scope resolution or (Phase 5) the new schema column, and a
sandbox Playwright pass with a second website added to the sandbox
(`docker compose`) confirming both single-website (unchanged) and
two-website (new) behaviour, en_US + et_EE.

**Explicitly out of scope for this RFC:**

- Store-view-level engine tenants (the canonical-store-view rule already
  reduces a website to one store view for engine purposes; no store-view
  engine variant is planned).
- Store-group-level binding of any kind (store groups are not a binding
  unit — §Decision).
- A currency field on the wire contract (§5's additive note; cross-team,
  not assumed).
- Cross-website customer identity merging in the engine (each tenant sees
  only its own website's view of a shared customer).
- Any change to the native `Stores > Configuration > Smaily` surface beyond
  the `intelligence` group's scope flags (§3) — the broader question of
  keeping vs. removing that native surface is tracked separately
  (`docs/ADMIN_UI_TARGET_SPEC.md` §4, PRO-1369).

## 9. Risks / open questions

- **PRO-1459 — engine-side provisioning/billing confirmation is a hard
  dependency gate for Phase 4.** This RFC assumes the engine can issue and
  meter more than one tenant against the same merchant/organization from a
  single Magento install. That needs an explicit yes from the engine team
  before Phase 4 starts — it is not a plugin-side implementation risk, it is
  a precondition.
- **Asymmetric granularity may be confusing:** a website can have several
  Smaily marketing accounts (mode A, per language) but always exactly one
  engine tenant. This is the deliberate design (§Decision), but it is worth
  confirming the Settings UI makes that asymmetry legible rather than
  implying "one Smaily account" and "one engine tenant" are the same
  relationship.
- **In-flight queue row backfill (§4/§7):** the default-website assumption
  for rows already queued at migration time is safe (matches current
  behaviour) but should be double-checked against the integration test
  before the schema migration ships, since it is the one irreversible step
  in this plan.
- **Health-check notice volume (§3):** per-website health notices could
  produce more admin noise on a large multi-website install than today's
  single notice; no design is proposed here beyond "scope the existing
  notice per website" — worth revisiting once Phase 4 is in front of a real
  multi-website sandbox.
