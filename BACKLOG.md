# Backlog

Deferred work items for the v3 line, in rough priority order.

## Hyvä support (separate work pack)

Compat module done and **verified on Hyvä 1.5.2** (`compat/hyva/`, module
`Hyva_SmailyConnect`): framework-free tracker/attribution delivery, Tailwind
personalization form, Tailwind-build registration. Full audit and the
executed verification matrix (Luma / Hyvä / strict CSP — all pass) in
[docs/HYVA_SUPPORT.md](docs/HYVA_SUPPORT.md). Remaining:

- Verify the `cart_add` submit-capture path against third-party
  AJAX-add-to-cart compat modules (programmatic `form.submit()` fires no
  submit event); fall back to a `private-content-loaded` cart diff if a
  real store shows gaps.
- Publish the compat module as its own composer package
  (`smaily/module-connect-hyva` — name decided; see HYVA_SUPPORT.md).
- Checkout opt-in checkbox for Hyvä Checkout (commercial product, own
  Magewire integration surface) — declared out of scope for the first
  Hyvä-support release; the server-side opt-in endpoint is already
  checkout-agnostic.

## Admin / UX

- Engine automations form: per-language `automation_map` editing
  (`language_mode: per_language`); MVP ships single-language maps.
- Backfill job cancel button in the admin grid (CLI/DB only for now).
- Setup wizard as a guided multi-step flow (the Getting Started checklist
  covers onboarding for now).
- Event Log "Send again" for a failed row, refusals worded from the server's
  own reason, and no double send on retry (PRO-2454, Woo parity).
- Smaily landing page as a Magento CMS widget — decided for 3.1 (PRO-2455).
- Fidelity check of the July admin design pack against the rendered admin
  (PRO-2456, UI/UX parity project).

## Sync / data

- A deactivated or refused Campaign Intelligence account is remembered, gates
  every send path and is stated in the merchant panel (PRO-2451, Woo parity).
- GDPR erasure must also erase local queue rows and anonymise stored payloads,
  not just the contact (PRO-2452, Woo parity).
- Abandoned-cart purchase marker `abandoned_cart_purchased_at`, so a recovered
  cart stops the reminder chain (PRO-2453, Woo parity).
- Abandoned-cart coverage for guests who abandon before the payment step:
  fall back to the `quote_address` billing email when
  `quote.customer_email` is still NULL (Magento fills it only at
  payment-info submit). Legacy-parity gap, not a regression.
- Per-store-view catalog i18n uses one representative store per language;
  per-website engine tenants are out of scope (one tenant per installation).
- MSI salable-quantity-per-website as the catalog `in_stock` source: deferred
  with the multi-website tenant work (PRO-1951 decision) — a catalog row is
  keyed on `sku` per tenant, so a per-website answer has nowhere to go until
  each website has its own tenant (RFC Phase 4, gated on PRO-1459). The legacy
  `is_in_stock` flag, which MSI keeps synced, is the source until then.
- Per-product dedupe of PENDING catalog ingest rows from product saves: since
  PRO-1967 a burst of stock moves on one product inside a flush window builds
  one row, but repeated product saves still queue one row each (they build at
  once; `CatalogIngest` collapses identical rows only within a single
  request). Measure first.
- Subscriber full-sync safety net (daily) — reconcile + live events cover the
  standing flows; evaluate whether a periodic re-baseline
  (`GET contact.php?list=1`) is needed at scale.
- Browse relay rate limiting (the engine rate-limits; a local limiter would
  cut noise from abusive clients).

## Quality

- Magento integration test suite (`Test/Integration`) + CI job with MySQL
  service; the upgrade migration currently has a scripted sandbox procedure
  (see TESTING.md).
- i18n translation files (`i18n/en_US.csv`, `et_EE.csv`).
- Storefront JS tests for tracker/attribution.

## Deliberate decisions to revisit

- **Browse beacon vs profiling opt-out:** the beacon is anonymous
  (visitor-token based), so a per-email consent gate cannot be applied at
  collection time under FPC; enforcement of the §10 profiling opt-out happens
  engine-side (opted-out contacts are excluded from recommendations). If the
  spec's "stop collection too" posture becomes a hard requirement, bind the
  opt-out to the visitor token via customer-data sections.
- **Browse `source: "plugin_magento"`:** not yet in the contract's constant
  list (`web, plugin_woo, plugin_shopify, make, custom`) — add it to
  RECENGINE_API_CONTRACT.md in the connect/re repos before any engine-side
  source-enum tightening.
- **Order item amounts are tax-inclusive** (what the shopper saw); Woo sends
  ex-tax. Both are valid engine inputs; documented in OrderPayloadBuilder.

## Upstream

- Marketplace re-submission (product name "Smaily Connect") and the fate of
  the existing listing. The composer package name `smaily/smailyformagento` is kept so
  existing installs upgrade via plain `composer update`. The full proposal
  package is drafted in [docs/UPSTREAM_PROPOSAL.md](docs/UPSTREAM_PROPOSAL.md).
