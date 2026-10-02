# Upstream proposal — adopt the Smaily Connect rewrite as Smaily's Magento 2 extension

**Audience:** the Smaily team that owns `sendsmaily/smaily-magento-extension`, the
`smaily/smailyformagento` composer package and the Magento Marketplace listing.
**From:** the fork (`erkkimarkus/magento-connect`), branch `v3`, at **3.0.0-rc2
(unreleased)**.
**Status:** proposal / decision request. Nothing irreversible has been done — no
composer release published, no Marketplace submission, no public GitHub release.
This document makes the case, lays out a staged review and hand-over plan, and ends
with the concrete go/no-go decisions.

---

## 1. Executive summary

The fork is a **ground-up rewrite** of the Smaily extension for Magento 2: module
`Smaily_Connect` (namespace `Smaily\Connect`), replacing `Smaily_SmailyForMagento`,
feature-aligned with the Smaily Connect plugins for WooCommerce and Shopify. It
preserves the entire 2.8.x feature set — subscriber synchronization, abandoned cart,
checkout opt-in, the product RSS feed — and adds contact-sync lawful-basis modes,
two-way consent sync, welcome/first-order automations with per-language routing,
durable delivery queues with admin event logs, historical imports, GDPR tooling, and
the optional **Campaign Intelligence** integration (catalog/customer/order/browse
ingest, attribution, engine-run automations). Full inventory: [CHANGELOG](../CHANGELOG.md),
[README](../README.md), [ARCHITECTURE](ARCHITECTURE.md).

Current state, all verifiable in the repo and its CI:

| | 2.8.x (upstream `master`) | v3 (fork, 3.0.0-rc2) |
|---|---|---|
| 2.8.x feature set | ✓ | ✓ preserved, with an automatic settings migration |
| Campaign Intelligence | — | ✓ full integration behind an optional connect step |
| Credential storage | plaintext API password in `core_config_data` | encrypted (Magento encryptor); legacy value re-encrypted on upgrade |
| Tests | none | **89 unit + 38 integration** (real MySQL 8.4), green in CI |
| Static analysis | none | phpcs (Magento2 standard, the Marketplace's own) + PHPStan level 6, clean |
| CI | none | GitHub Actions: unit matrix on PHP 8.1/8.3, phpcs/phpstan, integration job with a MySQL 8.4 service, daily contract-staleness guard |
| End-to-end | manual | Docker sandbox (Magento 2.4.8 + sample data); `setup:upgrade`/`setup:di:compile` verified; admin UX browser-validated end-to-end (wizard, config, grids, zero module JS console errors) |
| i18n | — | English (242 phrases, canonical) + full Estonian (`et_EE`) packs, admin + storefront |
| Platform floor | — | Magento Open Source / Adobe Commerce 2.4.4+ or Mage-OS, PHP 8.1–8.4, declared in composer.json |

## 2. Compatibility: existing installs upgrade seamlessly

The composer package name is **unchanged on purpose**: `smaily/smailyformagento`.
For a store on 2.8.x the upgrade is a normal `composer update` +
`bin/magento setup:upgrade` — no re-install, no re-configuration:

- **All settings migrate automatically** during `setup:upgrade`: API credentials
  (the previously plaintext password lands encrypted), subscriber sync settings,
  abandoned cart configuration, autoresponder mappings. Once migrated, the legacy
  `smaily/*` config rows (the plaintext password among them) are deleted, so a
  downgrade back to 2.8.x starts with empty settings.
- **Legacy schema is cleaned up safely**: the `reminder_date`/`is_sent` columns on
  the core `quote` table and the unused `smaily_customer_sync` table are dropped,
  with already-mailed abandoned-cart state carried over first — the upgrade can
  never cause a duplicate reminder.
- **Verified twice over**: the migration is covered by the integration suite
  against a real MySQL (`Test/Integration/Migration/`) and was verified end-to-end
  in the sandbox through Magento's real `setup:upgrade` patch pipeline (scripted
  procedure in [TESTING.md](../TESTING.md)).
- The merchant-facing upgrade path, including the few deliberate behavior changes
  to review, is documented in [UPGRADING.md](UPGRADING.md).

## 3. Proposed staged review

Rather than one monolithic review, we suggest four passes in this order — each is
independently checkable and front-loads the highest-risk surfaces:

1. **Wire contract** — [`RECENGINE_API_CONTRACT.md`](RECENGINE_API_CONTRACT.md)
   (v1.8.1, byte-synced across the Smaily connect repositories, drift guarded by a
   daily CI workflow). This is the smallest artifact with the widest blast radius:
   it defines what the extension sends to Campaign Intelligence and is shared with
   the WooCommerce and Shopify connectors.
2. **GDPR / consent surfaces** — contact-sync lawful-basis modes
   (`Model/ContactSync/`), two-way consent sync, the checkout opt-in checkbox
   (double opt-in respected), GDPR export/erase CLI, the shopper personalization
   opt-out page, and the browse beacon's behavior without cookie consent
   (sender-side anonymous mode). These are the surfaces where a mistake costs
   trust, so they deserve the second pass.
3. **The 2.8.x migration** — `Setup/Patch/`, `Model/Migration/`, the integration
   tests in `Test/Integration/Migration/` and the sandbox procedure in TESTING.md.
   This is what protects every existing install on upgrade day.
4. **Admin UX click-through** — the setup wizard, configuration page, event/ingest/
   backfill grids and Getting Started page, in the sandbox.

**Running everything locally** takes two commands sets, both documented in
[TESTING.md](../TESTING.md) and [CONTRIBUTING.md](../CONTRIBUTING.md):

```bash
composer install                                     # quality gates
vendor/bin/phpunit --testsuite unit
vendor/bin/phpcs && vendor/bin/phpstan analyse
vendor/bin/phpunit -c phpunit.integration.xml.dist   # needs a throwaway MySQL

docker compose up -d                                 # full Magento 2.4.8 sandbox
# admin at http://localhost:8080/admin
```

Review artifacts available today: this proposal, [ARCHITECTURE.md](ARCHITECTURE.md)
(how the module works inside), [USER_GUIDE.md](USER_GUIDE.md) (every setting
explained), [UPGRADING.md](UPGRADING.md), [TESTING.md](../TESTING.md), the CI runs
on the `v3` branch, and [BACKLOG.md](../BACKLOG.md) (known deferred work, stated
openly).

## 4. Marketplace re-submission as "Smaily Connect"

The public product name is **"Smaily Connect"** — Marketplace naming guidelines do
not allow "Magento" in extension names, so the listing needs re-submission under
the new name rather than a version bump of the old one. Proposed split:

| Step | Owner | Status |
|---|---|---|
| Coding-standard compliance (phpcs, Magento2 ruleset — the Marketplace's own standard) | fork | ✅ clean, enforced in CI |
| Release packaging — a GitHub release automatically builds the submission ZIP (`.github/workflows/release.yaml`) | fork | ✅ in place, and the package is built + verified on every push |
| Package manifest — `composer validate`, declared Magento dependencies, `type`/`autoload`/`license` | fork | ✅ checked, see below |
| Public documentation set (README, User Guide, Upgrading, screenshots for the listing) | fork | ✅ docs done, published on GitHub (not inside the ZIP — see below); listing screenshots to be produced at submission |
| Listing copy + name/branding decision ("Smaily Connect") | Smaily | ⏳ Smaily-owned |
| Marketplace account, submission, EQP review cycle, responding to reviewer feedback | Smaily | ⏳ Smaily-owned (same flow as today's releases — see `.github/pull_request_template.md`) |
| Decide fate of the existing listing (deprecate in favor of the new one vs. parallel run) | Smaily | ⏳ decision needed |

**Pre-check results** (run locally at 3.0.0-rc1, 2026-09-10 — all of it repeatable
from a clean checkout):

- `composer validate` passes. Under `--strict` it reports exactly one general
  warning — "the version field is present, it is recommended to leave it out if
  the package is published on Packagist". That warning is an **accepted item, not
  a defect** (Erkki's decision, 2026-09-10): the field stays, because the
  Marketplace's packaging guide lists `version` among the required fields and the
  module reads its own version out of composer.json (`Model\ModuleVersion`) for the
  admin's post-upgrade notice. CI runs plain `composer validate`; `--strict` is
  expected to stay yellow on exactly this one line and on nothing else.
- `type` is `magento2-module`, `license` `GPL-3.0-only`, autoload is PSR-4
  (`Smaily\Connect\` → the package root) plus `files: [registration.php]` — the
  Marketplace's expected shape.
- The `require` block was read against what the code actually uses:
  `magento/module-catalog-inventory` and `magento/module-ui` were used but
  undeclared and are now declared. `Magento\InventoryApi` and
  `Magento\InventorySourceDeductionApi` are referenced only from `etc/di.xml`
  plugin declarations and stay **undeclared on purpose** — MSI is removable, and a
  plugin declared on a class that does not exist is simply never wired (see
  `Plugin/Engine/SourceItemsSave.php`).
- Coding standard: `vendor/bin/phpcs` — the repo's config is the Marketplace's own
  `Magento2` ruleset — reports **0 errors and 929 warnings** across 198 files (the
  warnings are almost entirely missing or multi-line doc-block annotations, which
  the EQP gate does not fail on). `PHPCompatibility` for `8.1-8.4` is clean.
- Release ZIP: `bin/verify-release-zip.sh` builds the submission package the way
  the release workflow does and asserts its contents, its version and that every
  shipped PHP file parses, then prints a SHA-256 build hash — see
  [TESTING.md](../TESTING.md). **The package ships no `docs/`** (Erkki's decision,
  2026-09-10): the folder vendors the engine contract from a private repository
  and carries internal audits, so it must not travel to reviewers or merchants.
  README, CHANGELOG and LICENSE ship with the code; the documentation set lives on
  GitHub and the shipped README links to it by URL.

The Marketplace submission and the composer release are **independent knobs**:
composer installs (the majority path, per the 2.8.x install docs) work as soon as
the package version is published, regardless of Marketplace review state.

## 5. Pipeline and ownership transfer

The aim is that Smaily ends up owning the code, the pipeline and the release
button, exactly as it does today for 2.8.x.

- **Repository**: two workable routes — (a) push the `v3` branch to
  `sendsmaily/smaily-magento-extension` and make it the default branch (the fork's
  git remotes are already set up for this; 2.8.x history is shared — the fork
  branched from upstream `master`), or (b) transfer the fork repo wholesale and
  archive-redirect. Route (a) keeps everything in the repo Smaily already owns and
  is the recommendation. The legacy 2.8.x line stays available on `master` either
  way (the README already points there).
- **Branch policy**: `v3` is the working branch today; on adoption it becomes the
  default, with `master` frozen as the 2.8.x maintenance line until v3 is stable.
- **Release workflow**: publishing a GitHub release builds and attaches
  `smaily-connect-magento2.zip` (`.github/workflows/release.yaml`) — the same
  artifact used for Marketplace submission and manual installs. No external
  service, no secrets needed for releases. The workflow does not assemble the
  package itself: it calls `bin/verify-release-zip.sh`, which builds it through
  `bin/build-release-zip.sh` (the one owner of what ships) and refuses to hand
  over an archive that is missing a required file, carries development material
  or states the wrong version.
- **Secrets to carry over**: exactly one — `ENGINE_CONTRACT_READ_TOKEN`, a
  fine-grained PAT with `contents:read` on the Campaign Intelligence engine
  repository, used by the daily contract-staleness workflow to detect wire-contract
  drift. The workflow distinguishes "cannot check" (missing/expired token) from
  "contract stale", so a lapsed token is loud, not silent.
- **Composer publishing**: stays with Smaily (the `smaily/smailyformagento`
  package is Smaily's); the fork never publishes.

## 6. Open items, stated honestly

- **The Smaily-side happy paths are verified against a live Smaily account.**
  Test connection, live workflow dropdowns, storefront subscribe → contact sync,
  the subscriber backfill, guest checkout opt-in and the abandoned-cart / welcome
  automation triggers were each clicked through against a real Smaily test
  account and verified server-side over the Smaily API (the walk also caught and
  fixed a workflow-listing bug — see the changelog). What remains open is a pass
  against a live Campaign Intelligence tenant: the engine happy paths are
  verified against the contract mock only.
- **Hyvä is verified on a real Hyvä 1.5.2 store** — the compatibility module
  (`compat/hyva/`, module `Hyva_SmailyConnect`, to be published as
  `smaily/module-connect-hyva`) passed its full verification matrix, including a
  strict-CSP theme variant, with a Luma store view as the regression control.
  Audit and the executed matrix are in [HYVA_SUPPORT.md](HYVA_SUPPORT.md).
  Classic Luma/Blank themes are fully covered.
- **Campaign Intelligence dependency**: the extension builds against engine
  contract v1.8.1; the engine repository is currently private, which is why the
  staleness check needs a read token. Everything engine-related is optional at
  runtime — a store that never connects the engine gets the full classic feature
  set with zero engine traffic.
- Version is **3.0.0-rc2**: the release-candidate label reflects the remaining
  engine-tenant verification gap above, not known defects — gates are green, the
  release package is built and verified on every push (§4), and the sandbox is
  clean.

## 7. Decision checklist

These are the one-way doors, in the recommended order. Everything before a given
door is reversible; each door is cheap to hold and expensive to walk back.

1. **Share the fork with Smaily and start the staged review** (§3). First move;
   costs nothing irreversible, unlocks everything else.
2. **Live engine-tenant click-through** (§6) — the Smaily-account half is done;
   a pass against a real Campaign Intelligence tenant closes the last
   verification gap. Do before any tag.
3. **First public GitHub release (alpha/beta tag)** — creates a downloadable ZIP.
   Low blast radius (opt-in manual installs only), but it is the first public
   artifact of the rewrite; do it on the upstream repo, after door 1–2.
4. **Repo/pipeline hand-over** (§5): `v3` to `sendsmaily/smaily-magento-extension`,
   default-branch flip, secret carried over. After this, Smaily owns the button.
5. **Marketplace re-submission as "Smaily Connect"** (§4) — public listing under a
   new name; Smaily-owned, needs the listing-strategy decision for the old entry.
6. **Publish 3.0.0 stable to the composer package** — the true one-way door: once
   `smaily/smailyformagento:3.0.0` is published, every existing 2.8.x install whose
   version constraint allows it picks the rewrite up on its next
   `composer update`. This ships the migration to real stores and cannot be
   un-shipped; it goes last, after the review, the click-through and (ideally) a
   pilot store on the tagged pre-release.
