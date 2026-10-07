# Security audit — old upstream Magento plugin

**Date:** 2026-07-14
**Subject:** `Smaily_SmailyForMagento` v2.8.1 (the currently-published upstream
plugin, `master` branch — `sendsmaily/smaily-magento-extension`), targets
"Magento 2.3 and newer" per README; last `master` commit 2026-04-29.
**Purpose:** establish, with file-level evidence, whether the old plugin carries
a merchant-store-endangering security weakness — and whether the old upstream
needs a security fix *before* the v3 rewrite is merged/adopted.
**Method:** read-only review of every PHP/XML file in the module on `master`
(controllers, cron jobs, plugins, config backend models, HTTP client, setup
patches, ACL/routes/CSP). `Does v3 fix it?` column verified against the v3 tree.

## Bottom line

One genuine hardening gap (an API credential stored unencrypted at rest). It is
**not** a remotely-exploitable hole — no unauthenticated data-exposing endpoint,
no SQL injection, no RCE. It does **not** need to gate the v3 merge: adopting v3
resolves findings #1, #2 and #4 directly. The only open item is confirming that
an in-place old→v3 upgrade re-encrypts any pre-existing plaintext value rather
than leaving it behind.

## Findings

| # | Issue | Severity | Solution | Does v3 fix it? |
|---|-------|----------|----------|-----------------|
| 1 | **Smaily API password stored unencrypted** in `core_config_data` — the `system.xml` password field has no `Encrypted` backend model, so `type="password"` only masks the admin UI, not storage. `Helper/Config.php::getSmailyApiCredentials()` reads it back as a plain string. Readable in cleartext by anyone with DB/backup access. | **Medium–High** (data-at-rest) | Add `backend_model="Magento\Config\Model\Config\Backend\Encrypted"` to the password field + a one-time upgrade patch to re-encrypt existing values. | ✅ **Yes** — v3 uses `type="obscure"` + the `Encrypted` backend model (`etc/adminhtml/system.xml:40–43`). |
| 2 | **Google reCAPTCHA secret key stored unencrypted** — same missing-`Encrypted` gap in the same config group (`captchaApiSecret`). | **Low** | Same fix (Encrypted backend model). | ✅ **N/A — feature removed.** v3 has no reCAPTCHA field, so the gap does not exist. |
| 3 | **Public unauthenticated RSS endpoint** (`/smaily/rss/feed`, `Controller/Rss/Feed.php`) — no ACL/auth. Serves only storefront-visible catalog data (name, price, image, URL); the category param goes through `strip_tags()` into a parameterized EAV `like` filter. **By design, not a vulnerability.** | **Low / informational** | None needed — no sensitive data exposed, no injection vector. | ➖ **Retained by design.** v3 keeps the same public feed (`Controller/Rss/Feed.php` + frontend route); still not a vulnerability. |
| 4 | **No PHP/Magento version constraints** in `composer.json` (README says "Magento 2.3+"). Not a code bug, but permits install on EOL Magento cores carrying unpatched core CVEs. | **Low / packaging** | Pin supported PHP/Magento versions in `composer.json`. | ✅ **Yes** — v3 pins `php ~8.1–8.4` and explicit `magento/*` version ranges (`composer.json` require block). |

## Claim-check (two specific prior claims)

- **"Raw SQL directly in code" — FALSE.** Every DB touchpoint checked
  (`Cron/AbandonedCart.php`, `Cron/SubscribersSync.php`, all
  `Setup/Patch/Data/*.php`, `Model/ResourceModel/SubscribersSyncState.php`) uses
  Magento's `?`-placeholder binding. No string-concatenated queries anywhere;
  no SQL-injection vector. This half of the public claim should **not** be
  repeated as stated.
- **"Passwords stored as plaintext in the database" — TRUE.** This is finding
  #1, confirmed via `etc/adminhtml/system.xml` (missing `Encrypted` backend
  model) and `Helper/Config.php` (plain `scopeConfig->getValue` round-trip, no
  decrypt call). The one claim that holds up with hard evidence.

## Impact scenario (finding #1)

The live Smaily API username, password and subdomain sit in `core_config_data`
as readable text. This is not anonymously triggerable — it requires some other
read path to the database or a backup: a shared/leaked DB dump, an
`app:config:dump` export handed to an agency, a compromised hosting/DB account,
or an insider. In that case the credentials are exposed in the clear, enabling a
third party to send email/SMS to the merchant's contact list or pull subscriber
PII via the Smaily API. A credential-hardening gap, not an active breach.

## Recommendation

The honest framing for the Smaily team: *"The old plugin has no SQL-injection
issue — that part was overstated. It does store the Smaily API credentials
unencrypted at rest, which is a real hardening gap exploitable only by someone
with database/backup access. v3 already stores those credentials encrypted, so
adopting v3 resolves it. If old installs are upgraded in place, add a one-time
re-encryption step so existing plaintext values don't linger."*

## Open item

Confirm that an in-place old→v3 upgrade re-encrypts the pre-existing plaintext
`core_config_data` password value (or add an upgrade data-patch that does), so
old plaintext rows don't persist under the new schema.
