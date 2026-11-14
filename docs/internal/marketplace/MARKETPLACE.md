# Adobe Commerce Marketplace submission — Smaily Connect 3.0.0

The kit for submitting version 3.0.0 to the existing Marketplace listing of
`smaily/smailyformagento`, which shows version 2.8.0. The listing's
composer package name stays the same, so 3.0.0 is a **new version of that
listing**, not a new product. Submission happens in the Commerce Developer
Portal, from the Smaily vendor account (CON-152).

Files here:

| File | Use in the portal |
|---|---|
| `smaily-connect-user-guide.pdf` | Documentation upload: the user guide, in English (Marketplace wants a PDF) |
| `user-guide.html` | Source of the PDF; print it with Chrome to rebuild it (see below) |
| `smaily-icon-240.png` | Extension icon, 240 × 240 px |
| `screenshots/1-dashboard.png` | Gallery image: Dashboard |
| `screenshots/2-initial-setup.png` | Gallery image: the initial setup, step 2, the contact sync mode |
| `screenshots/3-automations.png` | Gallery image: Settings › Automations |
| `screenshots/4-intelligence.png` | Gallery image: Settings › Intelligence |
| the release asset `smaily-connect-magento2.zip` of [3.0.0](https://github.com/sendsmaily/smaily-magento-extension/releases/tag/3.0.0) | The package upload |

The screenshots come from the docker sandbox (Magento 2.4.8-p4, sample
data), cropped to the module's page: no admin menu, no Magento logo, no
system messages. Two values are neutral for the listing: the Campaign
Intelligence store shows as "Demo Store", and the three automation
workflows have neutral names in place of the demo account's own.

Rebuild the PDF after an edit of `user-guide.html`:

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --no-pdf-header-footer \
  --print-to-pdf="$PWD/docs/internal/marketplace/smaily-connect-user-guide.pdf" \
  "file://$PWD/docs/internal/marketplace/user-guide.html"
```

## Technical pre-check (2026-11-14, on the 3.0.0 release ZIP)

Against Adobe's [technical review guidelines](https://developer.adobe.com/commerce/marketplace/guides/sellers/technical-review-guidelines):

| Rule | Result |
|---|---|
| Zip archive with `composer.json`, under 30 MB | ✅ 2.9 MB unpacked; `composer.json` at the root |
| `name`, `type`, `version` | ✅ `smaily/smailyformagento`, `magento2-module`, `3.0.0` |
| No `extra.map` / `extra.magento-root-dir`, no forbidden dependency, no inline alias | ✅ |
| `etc/module.xml`, `registration.php` in `autoload.files`, a PSR-4 namespace | ✅ |
| No `*` constraint on a Magento-vendor package | ⚠️ `require` is clean; `require-dev` has `magento/magento-coding-standard: *` — the guideline does not say whether `require-dev` counts |
| No TODO / FIXME comments | ✅ none in PHP, PHTML, JS or XML |
| Coding standard | ✅ 0 errors, 2,092 warnings with `magento/magento-coding-standard` 41 (the newest), `--standard=Magento2` on the package |
| PHP versions not narrower than the claimed Commerce versions | ⚠️ see "Compatibility" |
| Installation with Varnish, production mode | ✅ by design: `setup:di:compile` runs in CI on 2.4.4-p13 and 2.4.9; only the module's own pages (personalization opt-out, the recommendations endpoint) are not cacheable |
| `repositories` key in `composer.json` | ⚠️ the Mage-OS mirror (development only) is declared; the guideline does not mention the key |

If the automated review refuses either ⚠️ item, the fix is a 3.0.1 that
pins `magento/magento-coding-standard` in `require-dev` and drops
`repositories` from the published `composer.json` (the development
mirror can move into a local `auth`/config file).

## Compatibility to claim

The package requires PHP `~8.1 || ~8.2 || ~8.3 || ~8.4`. Marketplace
refuses a package whose PHP range is narrower than a claimed Commerce
version's:

| Commerce | Its PHP (`magento/framework`) | Claim? |
|---|---|---|
| 2.4.4, 2.4.5 | 7.4, 8.1 | no — 7.4 is outside the package's range |
| 2.4.6 | 8.1, 8.2 | yes |
| 2.4.7 | 8.1, 8.2, 8.3 | yes |
| 2.4.8 | 8.2, 8.3, 8.4 | yes |
| 2.4.9 | 8.3, 8.4, 8.5 | no — 8.5 is outside the package's range (follow-up: test on PHP 8.5, widen the range in 3.0.1, then claim 2.4.9) |

Claim **Magento Open Source and Adobe Commerce (on-premises and cloud)
2.4.6, 2.4.7 and 2.4.8**. The module itself still installs on 2.4.4 and
2.4.5 with PHP 8.1 (README, Packagist); only the Marketplace claim is
narrower.

## Listing

Rules: Adobe's [marketing review guidelines](https://developer.adobe.com/commerce/marketplace/guides/sellers/marketing-review-guidelines)
— a title of at most five words, no "Magento", "Adobe", "M2",
"Extension", "Module" or "Plugin"; every fee in **bold** in the long
description's opening paragraph; no logos of Adobe or Magento.

**Title:** `Smaily Connect` (the listing shows "Smaily Ecommerce
Integration" today; Smaily is the integrated service, so its name is
allowed).

**Category:** the email-marketing category the portal offers (Marketing › Email Marketing or its current name).

**Price:** Free.

**Short description:**

> Sync subscribers and customers to Smaily, run welcome, first-order and
> abandoned-cart automations, and add Smaily Campaign Intelligence
> product recommendations to your emails and store.

**Long description:**

> Smaily Connect connects your store to Smaily email marketing.
> **The extension is free; it needs a Smaily account with a package that
> includes API access. Campaign Intelligence is an optional paid Smaily
> add-on (€250 per month on top of the Smaily subscription).**
>
> **Contacts.** New subscribers and customers reach Smaily as they happen,
> and an unsubscribe in Smaily comes back to the store. Pick the lawful
> basis that matches how you asked for permission: subscribers only
> (consent), all customers (legitimate interest) or checkout opt-in only.
> An optional newsletter checkbox at checkout serves guests and customers.
>
> **Automations.** Welcome, First order and Abandoned cart start the Smaily
> workflows you pick, optionally with a single-use discount code from a
> cart price rule. An abandoned-cart reminder carries the cart's products
> and a secure link that restores the exact cart. Multilingual stores
> route each language to its own workflow or Smaily account.
>
> **Campaign Intelligence (optional).** Catalog, customer, order and
> consented browsing data power personalized product recommendations in
> Smaily emails and on your store's pages (a widget), with attribution of
> the orders they bring, and automated campaigns such as replenishment and
> win-back.
>
> **Built for production.** A guided initial setup, one Settings page, a
> Dashboard and a Log of every delivery with retry. Deliveries go through
> durable queues with retries; imports of existing data run in the
> background without slowing the store. Websites can be set up as one
> store or as separate stores with their own Smaily accounts.
>
> **Privacy.** Credentials are stored encrypted. Browse tracking waits for
> the visitor's marketing consent. Shoppers can opt out of
> personalization, and GDPR export and erasure run from the command line.
> When a Smaily account is connected, the extension sends Smaily an
> installation report — store addresses, versions, connected accounts,
> switched-on features and connection health, never customer, subscriber
> or order data.
>
> English and Estonian admin and storefront; storefront texts also in
> Russian, Finnish, Latvian and Lithuanian. Full user guide:
> https://smaily.com/connect-magento/

**Release notes (3.0.0):**

> Smaily Connect 3.0.0 is a rewrite of Smaily for Magento 2.8 under the
> name Smaily Connect. New: a guided initial setup, a Dashboard, a Log of
> every delivery with retry, contact sync modes with two-way unsubscribes,
> discount codes in automations, per-language Smaily accounts and
> workflows, separate stores per website, Campaign Intelligence product
> recommendations, browse tracking with consent, GDPR tools and the
> installation report. Upgrading from 2.8.x: run
> `composer require smaily/smailyformagento:^3.0` and `setup:upgrade`;
> the settings migrate automatically, and the user guide lists what
> changes.

**Support:** support@smaily.com · documentation
https://smaily.com/connect-magento/

**Icon:** `smaily-icon-240.png` — the Smaily mark from smaily.com (`Smly-favicon-500x500.png`, the site's own icon), scaled to the 240 × 240 px the portal asks for.

## Submission steps (portal)

1. Sign in to the Commerce Developer Portal with the Smaily vendor
   account; open the existing extension `smaily/smailyformagento`.
2. Add a new version: upload `smaily-connect-magento2.zip` (3.0.0), set
   the compatibility above, the release notes, and the documentation PDF.
3. Marketing: title, category, short and long description, gallery
   images, support details as above.
4. Submit both reviews (they run in parallel). Technical review results
   appear on the product's status page; answer refusals with a fixed
   package (3.0.1) and resubmit.

Not on Marketplace: the Hyvä companion `smaily/module-connect-hyva`. It
requires `hyva-themes/magento2-theme-module`, which Marketplace does not
serve; Hyvä stores keep installing it from Packagist.
